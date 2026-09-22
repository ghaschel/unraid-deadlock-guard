<?php
use DeadlockGuard\{Store,Jobs,Coordinator,Config,UncertainOperation};
test('opposing processes cannot acquire the same groups; duplicates join across processes',function(){
    foreach([false,true] as $duplicate){
        $d=tempdir();$s=new Store($d.'/run',$d.'/config');$a=member('docker','a');$b=member('docker','b');$s->saveConfig(config([group('g',[$a,$b])]));
        $script=$d.'/submit.php';$bootstrap=dirname(__DIR__,2).'/source/usr/local/emhttp/plugins/deadlock-guard/lib/bootstrap.php';
        file_put_contents($script,'<?php require '.var_export($bootstrap,true).';while(!is_file($argv[1]."/go"))usleep(1000);try{$s=new DeadlockGuard\\Store($argv[1]."/run",$argv[1]."/config");$j=(new DeadlockGuard\\Jobs($s))->submit([["workload"=>["type"=>"docker","id"=>$argv[2]],"action"=>"start"]],$argv[3]);echo $j["id"];}catch(Throwable $e){echo $e->getMessage();}');
        $procs=[];$pipes=[];foreach(['a',$duplicate?'a':'b'] as $n=>$id)$procs[$n]=proc_open([PHP_BINARY,$script,$d,$id,'process-'.$n],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes[$n]);
        touch($d.'/go');$out=[];foreach($procs as $n=>$proc){fclose($pipes[$n][0]);$out[]=stream_get_contents($pipes[$n][1]);fclose($pipes[$n][1]);fclose($pipes[$n][2]);proc_close($proc);}
        eq(count($s->active()),1);if($duplicate)eq($out[0],$out[1]);else eq(count(array_filter($out,fn($x)=>str_contains($x,'busy'))),1);
    }
});
test('uncertain platform operation keeps reservation and records actual target state',function(){
    [$s,$base,$j,$a,$b]=scenario('docker','vm');
    $p=new class([$a,$b]) extends SimulatedPlatform {public function act(array $m,string $action):void{$this->states[Config::key($m)]['status']='running';throw new UncertainOperation('connection lost');}};
    (new Coordinator($s,$p))->run($j['id']);$job=$s->job($j['id']);eq($job['status'],'quarantined');eq($job['states'][Config::key($b)]['status'],'running');ok($job['inFlight']!==null);
});
test('valid VM resume and wake preserve action; config changes refuse active jobs',function(){
    foreach(['resume'=>'paused','wake'=>'suspended'] as $action=>$state){[$s,$p,$old,$a,$b]=scenario('docker','vm');$s->updateJob($old['id'],['status'=>'failed']);$p->states[Config::key($b)]['status']=$state;$j=(new Jobs($s))->submit([['workload'=>$b,'action'=>$action]],'action-'.$action);raises(fn()=>$s->saveConfig(config([])),'active');(new Coordinator($s,$p))->run($j['id']);eq($s->job($j['id'])['status'],'succeeded');eq(end($p->log),$action.':'.Config::key($b));}
});
