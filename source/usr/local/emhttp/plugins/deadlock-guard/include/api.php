<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib/bootstrap.php';
use DeadlockGuard\{Store,Security,Jobs,Config,NativePlatform,Lifecycle,Launcher,Router,Recovery};
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try{
    $b=Security::request();$s=Store::system();$p=new NativePlatform($s);$l=new Lifecycle($s);$op=$b['op']??'';
    switch($op){
        case 'snapshot':$out=['config'=>$s->config(),'revision'=>$s->revision(),'inventory'=>$p->inventory(),'health'=>$l->health(),'jobs'=>array_map([Jobs::class,'publicJob'],$s->jobs())];break;
        case 'inventory':$out=$p->inventory();break;
        case 'config':
            if(!is_string($b['revision']??null))throw new RuntimeException('Configuration revision required');
            $out=['config'=>$s->saveConfig($b['config']??[],$b['revision']),'revision'=>$s->revision()];break;
        case 'route':
            $out=(new Router($s->config(),$p->inventory()))->route($b['native']??[]);
            if(!$out['managed'])break;
            $b['requests']=$out['requests'];
            // Managed routes are admitted immediately using the same server-side job path.
        case 'job':
            $req=Config::requests($b['requests']??[]);$plan=Config::plan($s->config(),$req);$l->assertReady($plan);
            $j=(new Jobs($s))->submit($req,$b['key']??'');
            if($j['status']==='queued')Launcher::worker($j['id']);
            $out=['managed'=>true,'job'=>Jobs::publicJob($j),'requests'=>$req];break;
        case 'status':$out=['job'=>Jobs::publicJob($s->job($b['id']??''))];break;
        case 'history':$out=['jobs'=>array_map([Jobs::class,'publicJob'],$s->jobs())];break;
        case 'console':$out=$p->console(Config::member($b['workload']??[]));break;
        case 'reconcile':$l->install();$out=['jobs'=>array_map([Jobs::class,'publicJob'],(new Recovery($s,$p))->reconcile()),'health'=>$l->health()];foreach($s->active() as $j)if($j['status']==='queued')Launcher::worker($j['id']);break;
        default:throw new RuntimeException('Unknown endpoint operation',400);
    }
    echo json_encode($out,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){$code=$e->getCode();http_response_code(in_array($code,[400,401,403,405,413],true)?$code:409);echo json_encode(['error'=>$e->getMessage()],JSON_INVALID_UTF8_SUBSTITUTE);}
