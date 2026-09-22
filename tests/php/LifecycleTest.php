<?php
use DeadlockGuard\{Lifecycle,Store};
test('install preserves foreign hooks and configuration; newly live hook needs activation',function(){
    $d=tempdir();$s=new Store($d.'/run',$d.'/boot/config.json');$s->saveConfig(config([]));$l=new Lifecycle($s,$d,fn()=>'daemon-1');
    mkdir($d.'/etc/libvirt/hooks/qemu.d',0755,true);file_put_contents($d.'/etc/libvirt/hooks/qemu.d/other','# foreign');
    $l->install();eq(file_get_contents($d.'/etc/libvirt/hooks/qemu.d/other'),'# foreign');eq($s->config(),config([]));ok(!$l->health()['ready']);
    $l2=new Lifecycle($s,$d,fn()=>'daemon-2');$l2->install();ok($l2->health()['ready']);$l2->remove();ok(is_file($s->configFile));ok(is_file($d.'/etc/libvirt/hooks/qemu.d/other'));ok(!is_file($d.'/etc/libvirt/hooks/qemu.d/99-deadlock-guard'));
});
test('refused removal and upgrade leave active handoffs and admission intact',function(){
    [$s,$p,$j,$a,$b]=scenario('docker','vm');$l=new Lifecycle($s,tempdir(),fn()=>null);
    raises(fn()=>$l->remove(),'Active');ok(!is_file($s->runDir.'/draining.json'),'Refused removal stranded the active worker');
    raises(fn()=>$l->prepareUpgrade(),'Active');ok(!is_file($s->runDir.'/draining.json'),'Refused update stranded the active worker');
    (new DeadlockGuard\Coordinator($s,$p))->run($j['id']);eq($s->job($j['id'])['status'],'succeeded');
});
test('checks cannot resurrect removal or rewrite persistent install intent',function(){
    $d=tempdir();$s=new Store($d.'/run',$d.'/boot/config');$l=new Lifecycle($s,$d,fn()=>null);$l->install();$marker=dirname($s->configFile).'/installed.json';
    touch($marker,123456789);$l->check();clearstatcache();eq(filemtime($marker),123456789);
    $l->remove();$l->check();ok(!is_file($marker),'Stale check re-enabled removed plugin');ok(!is_file($d.'/etc/libvirt/hooks/qemu.d/99-deadlock-guard'));
});
test('aborted updates recover admission only when updater is gone and payload is unchanged',function(){
    $d=tempdir();$s=new Store($d.'/run',$d.'/boot/config');mkdir($d.'/payload');file_put_contents($d.'/payload/code','old');$l=new Lifecycle($s,$d,fn()=>null,$d.'/payload');$l->install();
    $l->prepareUpgrade(getmypid());$l->check();ok(is_file($s->runDir.'/draining.json'),'Live updater barrier lost');
    $r=Store::read($s->runDir.'/draining.json');$r['owner']=['pid'=>99999999,'processIdentity'=>'gone'];Store::atomic($s->runDir.'/draining.json',$r);
    $l->check();ok(!is_file($s->runDir.'/draining.json'),'Unchanged interrupted update stranded admission');
    $l->prepareUpgrade(getmypid());$r=Store::read($s->runDir.'/draining.json');$r['owner']=['pid'=>99999999,'processIdentity'=>'gone'];Store::atomic($s->runDir.'/draining.json',$r);file_put_contents($d.'/payload/code','partial new payload');$l->check();ok(is_file($s->runDir.'/draining.json'),'Partial package replacement was treated as safe');
});
