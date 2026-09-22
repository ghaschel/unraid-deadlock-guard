<?php
declare(strict_types=1);
namespace DeadlockGuard;
use RuntimeException;
/** Intentionally independent of the Platform and command runner. Never call libvirt here. */
final class Gate
{
    public function __construct(private Store $store) {}
    public function issue(array $m,string $jobId): void
    {
        $job=$this->store->job($jobId);
        if($job['status']!=='running' || !ProcessIdentity::alive($job) || !in_array($m,array_column($job['plan']['requests'],'workload'),true))throw new RuntimeException('Cannot issue VM authorization');
        Store::atomic($this->store->permissionPath($m),['jobId'=>$jobId,'workload'=>$m,'expiresAt'=>microtime(true)+30,'nonce'=>bin2hex(random_bytes(16)),'processIdentity'=>$job['processIdentity']]);
    }
    public function revoke(array $m): void { $p=$this->store->permissionPath($m);if(is_file($p))unlink($p); }
    public function prepare(array $m): void
    {
        if(!Config::groupsFor($this->store->config(),$m))return;
        $p=$this->store->permissionPath($m);$lock=fopen($p.'.lock','c');
        if(!$lock || !flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('VM authorization is busy');
        try {
            if(!is_file($p))throw new RuntimeException('Start this VM using the Unraid VMs or Dashboard Start button (Deadlock Guard group)');
            $permit=Store::read($p);unlink($p);
            $job=$this->store->job($permit['jobId']??'');
            if(($permit['workload']??null)!==$m || ($permit['expiresAt']??0)<microtime(true) || $job['status']!=='running' || !ProcessIdentity::alive($job) || ($permit['processIdentity']??null)!==$job['processIdentity'])throw new RuntimeException('Invalid or expired VM authorization');
            $this->event($m,'prepare');
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    }
    public function event(array $m,string $phase): void
    {
        if(!in_array($phase,['prepare','started','stopped','release'],true))return;
        $p=$this->store->eventPath($m);$old=is_file($p)?Store::read($p):[];
        Store::atomic($p,['phase'=>$phase,'at'=>microtime(true),'release'=>$phase==='release'?microtime(true):($old['release']??0)]);
        if($phase==='release')$this->revoke($m);
    }
    public static function vmFromXml(string $xml): array
    {
        if(strlen($xml)>4*1024*1024 || stripos($xml,'<!DOCTYPE')!==false || stripos($xml,'<!ENTITY')!==false)throw new RuntimeException('Invalid domain XML');
        $old=libxml_use_internal_errors(true);
        try{$dom=simplexml_load_string($xml,'SimpleXMLElement',LIBXML_NONET);if($dom===false)throw new RuntimeException('Invalid domain XML');return Config::member(['type'=>'vm','id'=>(string)$dom->uuid]);}
        finally{libxml_clear_errors();libxml_use_internal_errors($old);}
    }
}
