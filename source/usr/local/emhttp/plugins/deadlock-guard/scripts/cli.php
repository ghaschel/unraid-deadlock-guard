<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib/bootstrap.php';
use DeadlockGuard\{Store,NativePlatform,Coordinator,Lifecycle,Recovery,Launcher};
if(PHP_SAPI!=='cli')exit(1);
try{
    $s=Store::system();$p=new NativePlatform($s);$l=new Lifecycle($s);$command=$argv[1]??'';
    switch($command){
        case 'worker':$id=$argv[2]??'';$plan=$s->job($id)['plan'];(new Coordinator($s,$p,fn()=>$l->assertReady($plan)))->run($id);break;
        case 'install':case 'activate':$l->activate();break;
        case 'check':$l->install();(new Recovery($s,$p))->reconcile();foreach($s->active() as $j)if($j['status']==='queued')Launcher::worker($j['id']);break;
        case 'drain':$l->drain();break;
        case 'upgrade':$l->drain();if($s->active())throw new RuntimeException('Finish or reconcile active handoffs before upgrading');break;
        case 'remove':$l->remove();break;
        default:throw new RuntimeException('Unknown lifecycle command');
    }
}catch(Throwable $e){fwrite(STDERR,'Deadlock Guard: '.$e->getMessage()."\n");exit(1);}
