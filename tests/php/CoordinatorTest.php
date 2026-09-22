<?php
use DeadlockGuard\Coordinator;
use DeadlockGuard\Config;
use DeadlockGuard\Store;
use DeadlockGuard\Jobs;
use DeadlockGuard\Platform;
// Platform double represents external Docker/libvirt only; the coordinator and store are real.
if (interface_exists(Platform::class)) {
class SimulatedPlatform implements Platform {
    public array $states=[],$log=[],$delays=[],$pending=[],$refuse=[];public float $time=0;public bool $failStart=false,$forceWorks=true,$releaseWorks=true;
    public function __construct(array $members){foreach($members as $m)$this->states[Config::key($m)]=['status'=>'stopped','name'=>$m['id'],'runtimeId'=>$m['id'],'release'=>0];}
    public function inspect(array $m): array { $k=Config::key($m);if(!isset($this->states[$k]))throw new RuntimeException('Missing workload');return $this->states[$k]; }
    public function stop(array $m): void {$k=Config::key($m);$this->log[]='stop:'.$k;if(empty($this->refuse[$k]))$this->pending[$k]=$this->time+($this->delays[$k]??0);}
    public function forceStop(array $m): void {$k=Config::key($m);$this->log[]='force:'.$k;if($this->forceWorks)$this->pending[$k]=$this->time;}
    public function act(array $m,string $action): void {$k=Config::key($m);foreach($this->states as $other=>$s)if($other!==$k && $s['status']!=='stopped')throw new RuntimeException('SAFETY: overlapping execution');$this->log[]=$action.':'.$k;if($this->failStart)throw new RuntimeException('Start rejected');$this->states[$k]['status']='running';}
    public function now(): float { return $this->time; }
    public function pause(): void {$this->time+=0.25;foreach($this->pending as $k=>$t)if($t<=$this->time){$this->states[$k]['status']='stopped';if($this->releaseWorks)$this->states[$k]['release']++;unset($this->pending[$k]);}}
}
}
function scenario(string $from,string $to,array $extra=[]): array {
    $ids=['vm'=>'11111111-1111-1111-1111-111111111111','docker'=>'a'];
    $a=member($from,$ids[$from]);$b=member($to,$to==='vm'?'22222222-2222-2222-2222-222222222222':'b');
    $p=tempdir();$s=new Store($p.'/run',$p.'/config.json');$s->saveConfig(config([group('gpu',[$a,$b],array_merge(['vmTimeout'=>1,'containerTimeout'=>1],$extra))]));
    $platform=new SimulatedPlatform([$a,$b]);$platform->states[Config::key($a)]['status']='running';
    $job=(new Jobs($s))->submit([['workload'=>$b,'action'=>'start']],'scenario-1');return [$s,$platform,$job,$a,$b];
}
test('all handoff directions wait for shutdown before start',function(){
    ok(class_exists(Coordinator::class),'Handoff coordinator is missing');
    foreach([['vm','docker'],['docker','vm'],['vm','vm'],['docker','docker']] as [$from,$to]){
        [$s,$p,$j,$a,$b]=scenario($from,$to);$p->delays[Config::key($a)]=0.5;
        (new Coordinator($s,$p))->run($j['id']);eq($s->job($j['id'])['status'],'succeeded');eq($p->log,['stop:'.Config::key($a),'start:'.Config::key($b)]);ok($p->time>=0.5);
    }
});
test('graceful timeout never starts target or forces without opt-in',function(){
    [$s,$p,$j,$a,$b]=scenario('docker','vm');$p->refuse[Config::key($a)]=true;
    (new Coordinator($s,$p))->run($j['id']);eq($s->job($j['id'])['status'],'failed');eq($p->log,['stop:'.Config::key($a)]);eq($p->inspect($b)['status'],'stopped');
});
test('opted-in force waits and starts only if force actually stops workload',function(){
    foreach([true,false] as $works){[$s,$p,$j,$a,$b]=scenario('vm','docker',['forceVm'=>true]);$p->refuse[Config::key($a)]=true;$p->forceWorks=$works;
        (new Coordinator($s,$p))->run($j['id']);eq($s->job($j['id'])['status'],$works?'succeeded':'failed');eq(in_array('start:'.Config::key($b),$p->log),$works);}
});
test('VM shutoff without release completion cannot start target',function(){
    [$s,$p,$j,$a,$b]=scenario('vm','docker');$p->releaseWorks=false;
    (new Coordinator($s,$p))->run($j['id']);eq($s->job($j['id'])['status'],'failed');eq($p->inspect($b)['status'],'stopped');
});
test('missing members prevent any stop and failed startup does not roll back',function(){
    [$s,$p,$j,$a,$b]=scenario('docker','vm');unset($p->states[Config::key($a)]);(new Coordinator($s,$p))->run($j['id']);eq($p->log,[]);
    [$s,$p,$j,$a,$b]=scenario('docker','vm');$p->failStart=true;(new Coordinator($s,$p))->run($j['id']);eq($s->job($j['id'])['status'],'failed');eq($p->inspect($a)['status'],'stopped');
});
test('paused and unknown conflicts are never mistaken for stopped',function(){
    foreach(['paused','suspended','restarting','unknown'] as $state){[$s,$p,$j,$a,$b]=scenario('docker','vm');$p->states[Config::key($a)]['status']=$state;$p->refuse[Config::key($a)]=true;
        (new Coordinator($s,$p))->run($j['id']);eq($p->inspect($b)['status'],'stopped');}
});
test('container restart uses graceful stop and start; VM restart remains guest reboot',function(){
    foreach(['docker','vm'] as $type){[$s,$p,$j,$a,$b]=scenario($type,$type);$s->updateJob($j['id'],['status'=>'failed']);$p->states[Config::key($a)]['status']='stopped';$p->states[Config::key($b)]['status']='running';
        $j=(new Jobs($s))->submit([['workload'=>$b,'action'=>'restart']],'restart-1');(new Coordinator($s,$p))->run($j['id']);
        eq($s->job($j['id'])['status'],'succeeded');eq($p->log,$type==='vm'?['restart:'.Config::key($b)]:['stop:'.Config::key($b),'start:'.Config::key($b)]);
    }
});
test('overlapping groups stop every conflict and use conservative force policy',function(){
    $a=member('docker','a');$b=member('docker','b');$c=member('docker','c');$d=tempdir();$s=new Store($d.'/run',$d.'/config');
    $cfg=config([group('one',[$a,$b],['containerTimeout'=>1,'forceContainer'=>true]),group('two',[$b,$c],['containerTimeout'=>2])]);$s->saveConfig($cfg);$p=new SimulatedPlatform([$a,$b,$c]);$p->states['docker:a']['status']='running';$p->states['docker:c']['status']='running';
    $j=(new Jobs($s))->submit([['workload'=>$b,'action'=>'start']],'overlap-1');(new Coordinator($s,$p))->run($j['id']);eq($s->job($j['id'])['status'],'succeeded');eq($p->log,['stop:docker:a','stop:docker:c','start:docker:b']);
});
