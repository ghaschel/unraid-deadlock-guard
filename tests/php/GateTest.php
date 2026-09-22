<?php
use DeadlockGuard\Gate;
use DeadlockGuard\Store;
use DeadlockGuard\ProcessIdentity;
test('hook admits a managed VM exactly once for a live running job',function(){
    ok(class_exists(Gate::class),'VM authorization gate is missing');
    [$s,$p,$j,$a,$b]=scenario('docker','vm');
    $s->updateJob($j['id'],['status'=>'running','pid'=>getmypid(),'processIdentity'=>ProcessIdentity::of(getmypid())]);
    $gate=new Gate($s);raises(fn()=>$gate->prepare($b),'Start this VM');
    $gate->issue($b,$j['id']);$gate->prepare($b);raises(fn()=>$gate->prepare($b),'Start this VM');
});
test('expired or orphaned VM permissions cannot be consumed',function(){
    [$s,$p,$j,$a,$b]=scenario('docker','vm');$s->updateJob($j['id'],['status'=>'running','pid'=>getmypid(),'processIdentity'=>ProcessIdentity::of(getmypid())]);$gate=new Gate($s);
    $gate->issue($b,$j['id']);$file=$s->permissionPath($b);$permit=Store::read($file);$permit['expiresAt']=1;Store::atomic($file,$permit);raises(fn()=>$gate->prepare($b),'authorization');
    $gate->issue($b,$j['id']);$s->updateJob($j['id'],['status'=>'quarantined']);raises(fn()=>$gate->prepare($b),'authorization');
});
test('unmanaged VM hook permits starts and lifecycle events require no platform',function(){
    [$s,$p,$j,$a,$b]=scenario('docker','vm');$g=new Gate($s);$other=member('vm','33333333-3333-3333-3333-333333333333');$g->prepare($other);
    $g->event($b,'release');$event=Store::read($s->eventPath($b));eq($event['phase'],'release');ok($event['release']>0);
});
