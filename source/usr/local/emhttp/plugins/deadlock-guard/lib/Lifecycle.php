<?php
declare(strict_types=1);
namespace DeadlockGuard;
use RuntimeException;
final class Lifecycle
{
    public function __construct(private Store $store,private string $root='',private ?\Closure $identity=null,private ?string $payloadDir=null){}
    private function daemon(): ?string{return $this->identity?($this->identity)():NativePlatform::serviceIdentity('vm');}
    private function hook(): string{return $this->root.'/etc/libvirt/hooks/qemu.d/99-deadlock-guard';}
    private function marker(): string{return dirname($this->store->configFile).'/installed.json';}
    private function payloadHash(): string
    {
        $dir=$this->payloadDir??dirname(__DIR__);$files=[];
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS)) as $file){
            if($file->isFile())$files[substr($file->getPathname(),strlen($dir))]=hash_file('sha256',$file->getPathname());
        }
        ksort($files);return hash('sha256',json_encode($files,JSON_THROW_ON_ERROR));
    }
    private function synchronize(bool $explicitInstall): void
    {
        // Caller holds registry lock, including the disabled check. A stale cron task must
        // never resurrect a hook after uninstall has removed its persistent intent marker.
        $installed=is_file($this->marker());
        if(!$explicitInstall && !$installed)return;
        if($explicitInstall && $this->store->active())throw new RuntimeException('Active handoffs prevent installation');
        if(!is_file($this->store->configFile)){
            if(!$explicitInstall || $installed)throw new RuntimeException('Configuration is missing; restore it before activating the plugin');
            Store::atomic($this->store->configFile,['version'=>1,'groups'=>[]]);
        }
        $this->store->config();
        $bytes=file_get_contents(dirname(__DIR__).'/scripts/qemu-hook');if($bytes===false)throw new RuntimeException('Hook payload missing');
        $hook=$this->hook();$activation=$this->store->runDir.'/activation.json';
        $same=is_file($hook) && hash_file('sha256',$hook)===hash('sha256',$bytes) && is_executable($hook);
        if(!$same){
            if($this->store->active())throw new RuntimeException('Cannot replace hook during an active handoff');
            if(is_file($hook) && !str_contains((string)file_get_contents($hook),'Owned by Deadlock Guard'))throw new RuntimeException('Hook filename belongs to another installation');
            if(!is_dir(dirname($hook)))mkdir(dirname($hook),0755,true);
            $tmp=$hook.'.new';if(file_put_contents($tmp,$bytes)!==strlen($bytes))throw new RuntimeException('Cannot install hook');chmod($tmp,0755);rename($tmp,$hook);
        }
        if(!$same || !is_file($activation))Store::atomic($activation,['blockedIdentity'=>$this->daemon(),'hash'=>hash('sha256',$bytes)]);
        if($explicitInstall && !$installed)Store::atomic($this->marker(),['installed'=>true]);
    }
    public function install(): void
    {
        $this->store->locked(function(){$this->synchronize(true);@unlink($this->store->runDir.'/draining.json');});
    }
    public function check(): void
    {
        $this->store->locked(function(){
            if(!is_file($this->marker()))return;
            $barrier=$this->store->runDir.'/draining.json';
            if(is_file($barrier)){
                $record=Store::read($barrier);
                if(($record['reason']??'')==='maintenance'){
                    // An updater may fail before replacing any code. Only release its barrier
                    // after that exact process is gone AND the complete payload is unchanged.
                    if(ProcessIdentity::alive($record['owner']??[]))return;
                    if(($record['payloadHash']??'')!==$this->payloadHash())return;
                    if($this->store->active())return;
                    unlink($barrier);
                }
            }
            $this->synchronize(false);
        });
    }
    public function health(): array
    {
        $reason=null;$daemon=$this->daemon();$path=$this->store->runDir.'/activation.json';
        if(!is_file($this->marker()))$reason='Plugin is not installed for activation';
        elseif(is_file($this->store->runDir.'/draining.json'))$reason='Array is stopping or plugin is undergoing maintenance';
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
        if(!is_file($this->marker()))throw new RuntimeException('Plugin is not installed');
        if(is_file($this->store->runDir.'/draining.json'))throw new RuntimeException('Plugin is draining');
        $v=@parse_ini_file($this->root.'/etc/unraid-version');$version=$v['version']??'';
        if(!preg_match('/^7\.3\.\d+(?:[-.].*)?$/D',$version))throw new RuntimeException('This beta requires Unraid 7.3.x');
        foreach(array_merge(array_column($plan['requests'],'workload'),$plan['conflicts']) as $m)if($m['type']==='vm'){
            $h=$this->health();if(!$h['ready'])throw new RuntimeException($h['message']);break;
        }
    }
    public function prepareUpgrade(?int $ownerPid=null): void
    {
        $ownerPid??=getmypid();$identity=ProcessIdentity::of($ownerPid);
        if($identity===null)throw new RuntimeException('Cannot identify the updater process');
        $this->store->locked(function()use($ownerPid,$identity){
            if($this->store->active())throw new RuntimeException('Active or quarantined jobs prevent upgrade; finish or reconcile them first');
            if(is_file($this->store->runDir.'/draining.json'))throw new RuntimeException('Plugin is already draining; check integration or retry after array startup');
            Store::atomic($this->store->runDir.'/draining.json',['reason'=>'maintenance','at'=>microtime(true),'owner'=>['pid'=>$ownerPid,'processIdentity'=>$identity],'payloadHash'=>$this->payloadHash()]);
        });
    }
    public function drain(): void{$this->store->locked(fn()=>Store::atomic($this->store->runDir.'/draining.json',['reason'=>'array','at'=>microtime(true)]));}
    public function activate(): void
    {
        $this->store->locked(function(){
            if(!is_file($this->marker()))return;
            $barrier=$this->store->runDir.'/draining.json';
            if(is_file($barrier) && (Store::read($barrier)['reason']??'')!=='array')return;
            $this->synchronize(false);@unlink($barrier);
        });
    }
    public function remove(): void
    {
        $this->store->locked(function(){
            if($this->store->active())throw new RuntimeException('Active or quarantined jobs prevent removal. Resolve them or reboot first. Configuration is retained.');
            Store::atomic($this->store->runDir.'/draining.json',['reason'=>'remove','at'=>microtime(true)]);
            // Disable persistent copies (including an unmounted libvirt image) before removal.
            @unlink($this->marker());
            $hook=$this->hook();if(is_file($hook) && str_contains((string)file_get_contents($hook),'Owned by Deadlock Guard'))unlink($hook);
            @unlink($this->store->runDir.'/activation.json');
        });
    }
}
