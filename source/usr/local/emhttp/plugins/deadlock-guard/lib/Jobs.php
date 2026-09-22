<?php
declare(strict_types=1);
namespace DeadlockGuard;
use RuntimeException;
final class Jobs
{
    public function __construct(private Store $store) {}
    public function submit(array $requests,string $key): array
    {
        if(!preg_match('/^[a-zA-Z0-9_-]{8,128}$/D',$key))throw new RuntimeException('Invalid idempotency key');
        $requests=Config::requests($requests);$fingerprint=hash('sha256',json_encode($requests,JSON_THROW_ON_ERROR));
        return $this->store->locked(function()use($requests,$key,$fingerprint){
            if(is_file($this->store->runDir.'/draining.json'))throw new RuntimeException('Plugin is draining');
            $config=$this->store->config();$plan=Config::plan($config,$requests);
            $touches=array_map([Config::class,'key'],array_merge(array_column($requests,'workload'),$plan['conflicts']));
            $jobs=$this->store->jobs();
            foreach($jobs as $j)if(in_array($key,$j['keys'],true)){
                if($j['fingerprint']!==$fingerprint)throw new RuntimeException('Idempotency key already used for a different request');
                return $j;
            }
            foreach($jobs as $j)if(!Store::terminal($j) && $j['fingerprint']===$fingerprint){
                if(count($j['keys'])>=128)throw new RuntimeException('Too many duplicate requests');
                $j['keys'][]=$key;$this->store->putJob($j);return $j;
            }
            foreach($jobs as $j)if(!Store::terminal($j) && (array_intersect($plan['groups'],$j['plan']['groups']) || array_intersect($touches,$j['touches'])))throw new RuntimeException('Group is busy: '.$j['id']);
            if(count($this->store->active())>=32)throw new RuntimeException('Too many active handoffs');
            $now=microtime(true);
            $job=['id'=>bin2hex(random_bytes(16)),'keys'=>[$key],'fingerprint'=>$fingerprint,'config'=>$config,'plan'=>$plan,'touches'=>$touches,'status'=>'queued','phase'=>'Queued','createdAt'=>$now,'updatedAt'=>$now,'error'=>null,'history'=>[],'states'=>[],'inFlight'=>null];
            $this->store->putJob($job);$this->store->prune();return $job;
        });
    }
    public static function publicJob(array $j): array
    {
        return array_intersect_key($j,array_flip(['id','status','phase','createdAt','updatedAt','error','history','states','inFlight']));
    }
}
