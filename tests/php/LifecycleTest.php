<?php
use DeadlockGuard\{Lifecycle,Store};
test('install preserves foreign hooks and configuration; newly live hook needs activation',function(){
    $d=tempdir();$s=new Store($d.'/run',$d.'/boot/config.json');$s->saveConfig(config([]));$l=new Lifecycle($s,$d,fn()=>'daemon-1');
    mkdir($d.'/etc/libvirt/hooks/qemu.d',0755,true);file_put_contents($d.'/etc/libvirt/hooks/qemu.d/other','# foreign');
    $l->install();eq(file_get_contents($d.'/etc/libvirt/hooks/qemu.d/other'),'# foreign');eq($s->config(),config([]));ok(!$l->health()['ready']);
    $l2=new Lifecycle($s,$d,fn()=>'daemon-2');$l2->install();ok($l2->health()['ready']);$l2->remove();ok(is_file($s->configFile));ok(is_file($d.'/etc/libvirt/hooks/qemu.d/other'));ok(!is_file($d.'/etc/libvirt/hooks/qemu.d/99-deadlock-guard'));
});
