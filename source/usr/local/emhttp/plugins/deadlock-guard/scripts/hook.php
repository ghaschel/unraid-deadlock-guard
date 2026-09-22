<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib/bootstrap.php';
use DeadlockGuard\{Gate,Store,Config};
// Do not include coordinator/platform code: this path must never make recursive libvirt calls.
$operation=$argv[2]??'';$sub=$argv[3]??'';
if(!in_array($operation,['prepare','started','stopped','release','migrate','restore','attach'],true))exit(0);
try{
    $xml=stream_get_contents(STDIN,4*1024*1024+1);$m=Gate::vmFromXml($xml);$s=Store::system();$g=new Gate($s);
    if($operation==='prepare' && $sub==='begin')$g->prepare($m);
    elseif(in_array($operation,['migrate','restore','attach'],true) && Config::groupsFor($s->config(),$m))throw new RuntimeException('Managed VM migration, restore and external attach are not supported; use an ordinary Start through Deadlock Guard.');
    elseif(in_array($operation,['started','stopped','release'],true))$g->event($m,$operation);
}catch(Throwable $e){fwrite(STDERR,'Deadlock Guard: '.$e->getMessage()."\n");exit(1);}
