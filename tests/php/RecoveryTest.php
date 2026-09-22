<?php
use DeadlockGuard\{Recovery,Store,Jobs,ProcessIdentity};
test('orphaned idle worker reconciles, uncertain operation never expires',function(){
    [$s,$p,$j,$a,$b]=scenario('docker','vm');$s->updateJob($j['id'],['status'=>'running','pid'=>99999999,'processIdentity'=>'gone']);
    (new Recovery($s,$p))->reconcile();eq($s->job($j['id'])['status'],'failed');
    $j=(new Jobs($s))->submit([['workload'=>$b,'action'=>'start']],'recovery-2');$s->updateJob($j['id'],['status'=>'running','pid'=>99999999,'processIdentity'=>'gone','inFlight'=>['action'=>'start','at'=>1]]);
    (new Recovery($s,$p))->reconcile();eq($s->job($j['id'])['status'],'quarantined');raises(fn()=>(new Jobs($s))->submit([['workload'=>$a,'action'=>'start']],'recovery-3'),'busy');
});
test('reconciliation never steals a live worker and draining prevents admission',function(){
    [$s,$p,$j,$a,$b]=scenario('docker','vm');$s->updateJob($j['id'],['status'=>'running','pid'=>getmypid(),'processIdentity'=>ProcessIdentity::of(getmypid())]);
    (new Recovery($s,$p))->reconcile();eq($s->job($j['id'])['status'],'running');
    Store::atomic($s->runDir.'/draining.json',['at'=>microtime(true)]);raises(fn()=>(new Jobs($s))->submit([['workload'=>$a,'action'=>'start']],'draining-1'),'draining');
});
