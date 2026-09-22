<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib/bootstrap.php';
use DeadlockGuard\{Store,NativePlatform,Coordinator,Lifecycle,Recovery,Launcher};
if(PHP_SAPI!=='cli')exit(1);
try{
    $s=Store::system();$p=new NativePlatform($s);$l=new Lifecycle($s);$command=$argv[1]??'';
    switch($command){
        case 'worker':$id=$argv[2]??'';$plan=$s->job($id)['plan'];(new Coordinator($s,$p,fn()=>$l->assertReady($plan)))->run($id);break;
        case 'install':$l->install();break;
        case 'activate':$l->activate();break;
        case 'check':$l->check();(new Recovery($s,$p))->reconcile();foreach($s->active() as $j)if($j['status']==='queued')Launcher::worker($j['id']);break;
        case 'drain':$l->drain();break;
        case 'upgrade':$l->prepareUpgrade(isset($argv[2]) && ctype_digit($argv[2])?(int)$argv[2]:null);break;
        case 'remove':$l->remove();break;
        default:throw new RuntimeException('Unknown lifecycle command');
    }
}catch(Throwable $e){fwrite(STDERR,'Deadlock Guard: '.$e->getMessage()."\n");exit(1);}
