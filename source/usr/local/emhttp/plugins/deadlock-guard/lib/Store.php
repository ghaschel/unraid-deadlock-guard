<?php
declare(strict_types=1);
namespace DeadlockGuard;
use RuntimeException;
final class Store
{
    public function __construct(public readonly string $runDir, public readonly string $configFile)
    {
        foreach ([$runDir,$runDir.'/jobs',$runDir.'/permissions',$runDir.'/events'] as $dir) {
            if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('Cannot create runtime directory');
            chmod($dir,0700);
        }
    }
    public static function system(): self { return new self('/var/run/deadlock-guard','/boot/config/plugins/deadlock-guard/config.json'); }
    public function locked(callable $fn): mixed
    {
        $f=fopen($this->runDir.'/registry.lock','c');
        if (!$f || !flock($f,LOCK_EX)) throw new RuntimeException('Cannot lock job registry');
        try { return $fn(); } finally { flock($f,LOCK_UN);fclose($f); }
    }
    public static function read(string $path): array
    {
        $data=@file_get_contents($path);
        if ($data===false) throw new RuntimeException('Cannot read '.basename($path));
        $v=json_decode($data,true,64,JSON_THROW_ON_ERROR);
        if (!is_array($v)) throw new RuntimeException('Invalid JSON record');
        return $v;
    }
    public static function atomic(string $path, array $value): void
    {
        $dir=dirname($path);
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('Cannot create storage directory');
        $tmp=tempnam($dir,'.write-');
        if ($tmp===false) throw new RuntimeException('Cannot create temporary record');
        try {
            chmod($tmp,0600);
            $f=fopen($tmp,'wb');if(!$f)throw new RuntimeException('Cannot open temporary record');
            try { $bytes=json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
                if(fwrite($f,$bytes)!==strlen($bytes) || !fflush($f))throw new RuntimeException('Cannot write complete record');
                if(function_exists('fsync'))fsync($f);
            } finally { fclose($f); }
            if(!rename($tmp,$path))throw new RuntimeException('Cannot publish record');
        } finally { if(is_file($tmp))unlink($tmp); }
    }
    public function config(): array
    {
        try { return Config::validate(self::read($this->configFile)); }
        catch(\Throwable $e){throw new RuntimeException('Cannot load configuration: '.$e->getMessage(),0,$e);}
    }
    public function saveConfig(array $config, ?string $expectedRevision=null): array
    {
        $config=Config::validate($config);
        return $this->locked(function()use($config,$expectedRevision){
            if($this->active())throw new RuntimeException('Cannot edit configuration while handoffs are active or quarantined');
            if($expectedRevision!==null && $expectedRevision!==$this->revision())throw new RuntimeException('Configuration changed; reload before saving');
            self::atomic($this->configFile,$config);return $config;
        });
    }
    public function revision(): string { return hash('sha256',json_encode($this->config(),JSON_THROW_ON_ERROR)); }
    public function jobPath(string $id): string
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('Invalid job ID');
        return $this->runDir.'/jobs/'.$id.'.json';
    }
    public function job(string $id): array { return self::read($this->jobPath($id)); }
    public function jobs(): array
    {
        $jobs=[];foreach(glob($this->runDir.'/jobs/*.json')?:[] as $path)$jobs[]=self::read($path);
        usort($jobs,fn($a,$b)=>$b['createdAt']<=>$a['createdAt']);return $jobs;
    }
    public static function terminal(array $job): bool { return in_array($job['status'],['succeeded','failed'],true); }
    public function active(): array { return array_values(array_filter($this->jobs(),fn($j)=>!self::terminal($j))); }
    public function putJob(array $job): void { self::atomic($this->jobPath($job['id']),$job); }
    public function updateJob(string $id,array $changes): array
    {
        return $this->locked(function()use($id,$changes){$job=array_replace($this->job($id),$changes);$this->putJob($job);return $job;});
    }
    public function prune(): void
    {
        $done=0;foreach($this->jobs() as $j)if(self::terminal($j) && ++$done>200)unlink($this->jobPath($j['id']));
    }
    public function eventPath(array $m): string { return $this->runDir.'/events/'.hash('sha256',Config::key($m)).'.json'; }
    public function permissionPath(array $m): string { return $this->runDir.'/permissions/'.hash('sha256',Config::key($m)).'.json'; }
}
