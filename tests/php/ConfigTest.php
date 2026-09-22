<?php
use DeadlockGuard\Config;
function member(string $type, string $id): array { return ['type'=>$type,'id'=>$id]; }
function group(string $id, array $members, array $extra=[]): array { return array_merge(['id'=>$id,'name'=>$id,'enabled'=>true,'members'=>$members],$extra); }
function config(array $groups): array { return Config::validate(['version'=>1,'groups'=>$groups]); }
test('configuration normalizes defaults and permits overlapping groups', function () {
    ok(class_exists(Config::class), 'Configuration implementation is missing');
    $vm=member('vm','11111111-1111-1111-1111-111111111111');
    $c=config([group('gpu',[$vm,member('docker','FileFlows')]),group('usb',[$vm,member('docker','HA')])]);
    eq($c['groups'][0]['vmTimeout'],120); eq($c['groups'][0]['containerTimeout'],30);
    eq($c['groups'][0]['forceVm'],false); eq($c['groups'][0]['forceContainer'],false);
    eq(count(Config::groupsFor($c,$vm)),2);
});
test('configuration rejects malformed members and policies',function(){
    foreach ([member('shell','x'),member('vm','not-a-uuid'),member('docker','a;touch /tmp/evil'),member('docker','-x')] as $bad) raises(fn()=>config([group('x',[$bad,member('docker','ok')])]),'Invalid');
    raises(fn()=>config([group('x',[member('docker','a'),member('docker','a')])]),'Duplicate');
    raises(fn()=>config([group('x',[member('docker','a'),member('docker','b')],['vmTimeout'=>0])]),'timeout');
    raises(fn()=>Config::validate(['version'=>2,'groups'=>[]]),'version');
});
test('batch conflict validation rejects same group before any execution',function(){
    $a=member('docker','a');$b=member('docker','b');$c=config([group('gpu',[$a,$b])]);
    raises(fn()=>Config::plan($c,[['workload'=>$a,'action'=>'start'],['workload'=>$b,'action'=>'start']]),'exclusive group');
    $p=Config::plan($c,[['workload'=>$a,'action'=>'start']]);eq($p['groups'],['gpu']);eq($p['conflicts'],[$b]);
});
