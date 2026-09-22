<?php
use DeadlockGuard\{Router,Config};
test('native routing resolves current container IDs and preserves unmanaged actions',function(){
    $a=member('docker','a');$b=member('docker','b');$cfg=config([group('g',[$a,$b])]);$inv=['workloads'=>[['type'=>'docker','id'=>'a','runtimeId'=>str_repeat('a',64),'status'=>'stopped'],['type'=>'docker','id'=>'free','runtimeId'=>str_repeat('b',64),'status'=>'stopped']],'errors'=>[]];
    $r=new Router($cfg,$inv);$out=$r->route(['type'=>'docker','id'=>str_repeat('a',12),'action'=>'start']);ok($out['managed']);eq($out['requests'][0]['workload'],$a);
    ok(!$r->route(['type'=>'docker','id'=>str_repeat('b',12),'action'=>'restart'])['managed']);raises(fn()=>$r->route(['type'=>'docker','id'=>'unknown','action'=>'start']),'Missing');raises(fn()=>$r->route(['type'=>'docker','id'=>str_repeat('a',12),'action'=>'delete']),'action');
});
test('bulk conflicts are rejected on the server before creating jobs',function(){
    $a=member('docker','a');$b=member('docker','b');$r=new Router(config([group('g',[$a,$b])]),['workloads'=>[array_merge($a,['status'=>'stopped']),array_merge($b,['status'=>'stopped'])],'errors'=>[]]);
    raises(fn()=>$r->route(['type'=>'docker','bulk'=>true,'action'=>'start']),'Choose one');
});
