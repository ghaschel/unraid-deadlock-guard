<?php
use DeadlockGuard\Store;
use DeadlockGuard\Jobs;
function fixtureJobs(): array {
    $p=tempdir();$s=new Store($p.'/run',$p.'/config.json');
    $a=member('docker','a');$b=member('docker','b');
    $s->saveConfig(config([group('gpu',[$a,$b])]));return [new Jobs($s),$s,$a,$b];
}
test('job admission is idempotent and reserves conflicting groups',function(){
    ok(class_exists(Store::class),'Persistent job storage is missing');
    [$j,$s,$a,$b]=fixtureJobs();$one=$j->submit([['workload'=>$a,'action'=>'start']],'request-1');
    eq($j->submit([['workload'=>$a,'action'=>'start']],'request-1')['id'],$one['id']);
    eq($j->submit([['workload'=>$a,'action'=>'start']],'request-2')['id'],$one['id']);
    raises(fn()=>$j->submit([['workload'=>$b,'action'=>'start']],'request-3'),'busy');
    raises(fn()=>$s->saveConfig(config([])),'active');
    raises(fn()=>$j->submit([['workload'=>$b,'action'=>'start']],'request-1'),'different');
});
test('reservations survive worker death and time passage',function(){
    [$j,$s,$a,$b]=fixtureJobs();$one=$j->submit([['workload'=>$a,'action'=>'start']],'old-request');
    $s->updateJob($one['id'],['status'=>'quarantined','updatedAt'=>1,'error'=>'Lost worker']);
    raises(fn()=>(new Jobs(new Store($s->runDir,$s->configFile)))->submit([['workload'=>$b,'action'=>'start']],'new-request'),'busy');
});
test('malformed configuration never silently becomes an empty config',function(){
    $p=tempdir();file_put_contents($p.'/config.json','{broken');$s=new Store($p.'/run',$p.'/config.json');
    raises(fn()=>$s->config(),'configuration');
});
test('invalid batch creates no jobs and no reservations',function(){
    [$j,$s,$a,$b]=fixtureJobs();raises(fn()=>$j->submit([['workload'=>$a,'action'=>'start'],['workload'=>$b,'action'=>'start']],'batch-001'),'exclusive group');eq($s->jobs(),[]);
});
