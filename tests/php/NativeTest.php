<?php
use DeadlockGuard\Runner;
use DeadlockGuard\NativePlatform;
use DeadlockGuard\CommandRunner;
use DeadlockGuard\Store;
use DeadlockGuard\Config;
if(interface_exists(CommandRunner::class)) {
class FixtureRunner implements CommandRunner {
    public array $calls=[];public string $signal='SIGTERM';public string $id;public bool $running=true;
    public function __construct(){$this->id=str_repeat('a',64);}
    public function run(array $argv,float $timeout=10,?string $input=null,bool $mutation=false): string {
        $this->calls[]=$argv;
        if($argv[0]==='docker' && $argv[1]==='inspect')return json_encode([['Id'=>$this->id,'Name'=>'/FileFlows','Config'=>['StopSignal'=>$this->signal,'Cmd'=>['/app/start']],'HostConfig'=>['RestartPolicy'=>['Name'=>'no'],'NetworkMode'=>'bridge'],'State'=>['Running'=>$this->running,'Restarting'=>false,'Paused'=>false,'Status'=>$this->running?'running':'exited','Pid'=>$this->running?123:0]]]);
        if($argv[0]==='docker' && $argv[1]==='kill')return $this->id;
        throw new RuntimeException('Unexpected command '.json_encode($argv));
    }
}
}
test('process runner preserves literal arguments and reports errors and deadlines',function(){
    ok(class_exists(Runner::class),'Bounded process runner is missing');$r=new Runner();
    $literal='x; touch /tmp/nope $(echo nope)';eq($r->run([PHP_BINARY,'-r','echo $argv[1];',$literal]),$literal);
    raises(fn()=>$r->run([PHP_BINARY,'-r','fwrite(STDERR,"bad command");exit(2);']),'bad command');
    raises(fn()=>$r->run([PHP_BINARY,'-r','sleep(4);'],0.05,null,true),'timed out');
});
test('native graceful Docker stop sends configured signal without implicit escalation',function(){
    $s=new Store(tempdir().'/run',tempdir().'/config.json');$r=new FixtureRunner();$p=new NativePlatform($s,$r);$m=member('docker','FileFlows');
    eq($p->inspect($m)['status'],'running');$p->stop($m);
    eq(end($r->calls),['docker','kill','--signal','SIGTERM','--',str_repeat('a',64)]);
    $r->signal='SIGKILL';$p->stop($m);eq(end($r->calls)[3],'SIGTERM');
    $p->forceStop($m);eq(end($r->calls)[3],'SIGKILL');
});
test('container recreation during a job is rejected rather than switching identity',function(){
    $s=new Store(tempdir().'/run',tempdir().'/config.json');$r=new FixtureRunner();$p=new NativePlatform($s,$r);$m=member('docker','FileFlows');$p->inspect($m);$r->id=str_repeat('b',64);raises(fn()=>$p->inspect($m),'changed');
    $new=new NativePlatform($s,$r);eq($new->inspect($m)['runtimeId'],str_repeat('b',64));
});
test('hook XML parsing rejects entities and takes UUID from domain XML',function(){
    $xml='<domain><name>Untrusted &amp; name</name><uuid>11111111-1111-1111-1111-111111111111</uuid></domain>';
    eq(DeadlockGuard\Gate::vmFromXml($xml),member('vm','11111111-1111-1111-1111-111111111111'));
    raises(fn()=>DeadlockGuard\Gate::vmFromXml('<!DOCTYPE x [<!ENTITY secret SYSTEM "file:///etc/passwd">]><domain/>'),'Invalid');
});
test('lost mutating command responses quarantine instead of reporting a definite failure',function(){
    $runner=new DeadlockGuard\Runner();$caught=null;try{$runner->run([PHP_BINARY,'-r','fwrite(STDERR,"transport disconnected");exit(1);'],2,null,true);}catch(Throwable $e){$caught=$e;}
    ok($caught instanceof DeadlockGuard\UncertainOperation,'Nonzero mutation may have been accepted by the daemon');
});
