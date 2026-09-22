<?php
declare(strict_types=1);
namespace DeadlockGuard;
use RuntimeException;
final class Lifecycle
{
    public function __construct(private Store $store,private string $root='',private ?\Closure $identity=null){}
    private function daemon(): ?string{return $this->identity?($this->identity)():NativePlatform::serviceIdentity('vm');}
    private function hook(): string{return $this->root.'/etc/libvirt/hooks/qemu.d/99-deadlock-guard';}
    public function install(): void
    {
        $this->store->locked(function(){
            if(!is_file($this->store->configFile))Store::atomic($this->store->configFile,['version'=>1,'groups'=>[]]);
            Store::atomic(dirname($this->store->configFile).'/installed.json',['installed'=>true]);
            $this->store->config(); // Never replace an invalid existing configuration.
            $source=dirname(__DIR__).'/scripts/qemu-hook';$bytes=file_get_contents($source);if($bytes===false)throw new RuntimeException('Hook payload missing');
            $hook=$this->hook();$marker=$this->store->runDir.'/activation.json';
            $same=is_file($hook) && hash_file('sha256',$hook)===hash('sha256',$bytes) && is_executable($hook);
            if(!$same){
                if($this->store->active())throw new RuntimeException('Cannot replace hook during an active handoff');
                if(is_file($hook) && !str_contains((string)file_get_contents($hook),'Owned by Deadlock Guard'))throw new RuntimeException('Hook filename belongs to another installation');
                if(!is_dir(dirname($hook)))mkdir(dirname($hook),0755,true);
                $tmp=$hook.'.new';if(file_put_contents($tmp,$bytes)!==strlen($bytes))throw new RuntimeException('Cannot install hook');chmod($tmp,0755);rename($tmp,$hook);
            }
            // Capture the daemon present when installing/checking for the first time this boot.
            // New hook discovery is only guaranteed after a daemon restart.
            if(!$same || !is_file($marker))Store::atomic($marker,['blockedIdentity'=>$this->daemon(),'hash'=>hash('sha256',$bytes)]);
        });
    }
    public function health(): array
    {
        $reason=null;$daemon=$this->daemon();$path=$this->store->runDir.'/activation.json';
        if(is_file($this->store->runDir.'/draining.json'))$reason='Array is stopping or plugin is draining';
        elseif(!is_file($path) || !is_file($this->hook()))$reason='Hook is missing; run the plugin integration check';
        else{
            $record=Store::read($path);
            if(!is_executable($this->hook()) || hash_file('sha256',$this->hook())!==$record['hash'])$reason='Hook integrity check failed';
            elseif($daemon===null)$reason='VM service is unavailable';
            elseif($daemon===$record['blockedIdentity'])$reason='Pending activation: stop and start the VM service when safe. Deadlock Guard will not restart it.';
        }
        return ['ready'=>$reason===null,'message'=>$reason??'VM safety gate installed and activated'];
    }
    public function assertReady(array $plan): void
    {
        if(is_file($this->store->runDir.'/draining.json'))throw new RuntimeException('Plugin is draining');
        $v=@parse_ini_file($this->root.'/etc/unraid-version');$version=$v['version']??'';
        if(!preg_match('/^7\.3\.\d+(?:[-.].*)?$/D',$version))throw new RuntimeException('This beta requires Unraid 7.3.x');
        foreach(array_merge(array_column($plan['requests'],'workload'),$plan['conflicts']) as $m)if($m['type']==='vm'){
            $h=$this->health();if(!$h['ready'])throw new RuntimeException($h['message']);break;
        }
    }
    public function drain(): void{$this->store->locked(fn()=>Store::atomic($this->store->runDir.'/draining.json',['at'=>microtime(true)]));}
    public function activate(): void{$this->install();$this->store->locked(function(){@unlink($this->store->runDir.'/draining.json');});}
    public function remove(): void
    {
        $this->drain();$this->store->locked(function(){
            if($this->store->active())throw new RuntimeException('Active or quarantined jobs prevent removal. Resolve them or reboot first. Configuration is retained.');
            $hook=$this->hook();if(is_file($hook) && str_contains((string)file_get_contents($hook),'Owned by Deadlock Guard'))unlink($hook);
            @unlink($this->store->runDir.'/activation.json');
            @unlink(dirname($this->store->configFile).'/installed.json');
        });
    }
}
