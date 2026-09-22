<?php
declare(strict_types=1);
namespace DeadlockGuard;
use RuntimeException;
final class NativePlatform implements Platform
{
    private array $bindings=[];
    public function __construct(private Store $store,private CommandRunner $runner=new Runner()) {}
    public function now(): float{return hrtime(true)/1e9;}
    public function pause(): void{usleep(250000);}
    public static function serviceIdentity(string $type): ?string
    {
        $path=$type==='vm'?'/var/run/libvirt/libvirtd.pid':'/var/run/dockerd.pid';
        $pid=trim((string)@file_get_contents($path));return ctype_digit($pid)?ProcessIdentity::of((int)$pid):null;
    }
    private function docker(array $m): array
    {
        $data=json_decode($this->runner->run(['docker','inspect','--type','container','--',$m['id']]),true,64,JSON_THROW_ON_ERROR);
        if(!is_array($data) || count($data)!==1 || ($data[0]['Name']??'')!=='/'.$m['id'] || !preg_match('/^[a-f0-9]{64}$/D',$data[0]['Id']??''))throw new RuntimeException('Missing or ambiguous container: '.$m['id']);
        $ct=$data[0];$key=Config::key($m);
        if(isset($this->bindings[$key]) && $this->bindings[$key]!==$ct['Id'])throw new RuntimeException('Container identity changed during handoff: '.$m['id']);
        $this->bindings[$key]=$ct['Id'];return $ct;
    }
    private function virsh(string $command,array $m,array $extra=[],bool $mutation=false,float $timeout=10): string
    {
        return $this->runner->run(array_merge(['virsh','--connect','qemu:///system',$command,'--domain',$m['id']],$extra),$timeout,null,$mutation);
    }
    public function inspect(array $m): array
    {
        $m=Config::member($m);
        if($m['type']==='docker'){
            $ct=$this->docker($m);$s=$ct['State']??[];
            $status=match(true){!empty($s['Restarting'])=>'restarting',!empty($s['Paused'])=>'paused',!empty($s['Running'])=>'running',in_array($s['Status']??'', ['exited','created'],true) && ($s['Running']??null)===false && (int)($s['Pid']??-1)===0=>'stopped',default=>'unknown'};
            $auto=array_map(fn($x)=>preg_split('/\s+/',trim($x))[0],@file('/var/lib/docker/unraid-autostart',FILE_IGNORE_NEW_LINES)?:[]);
            return ['status'=>$status,'name'=>$m['id'],'runtimeId'=>$ct['Id'],'release'=>0,'restartPolicy'=>$ct['HostConfig']['RestartPolicy']['Name']??'unknown','autostart'=>in_array($m['id'],$auto,true),'serviceIdentity'=>self::serviceIdentity('docker')];
        }
        $text=$this->virsh('dominfo',$m);$info=[];
        foreach(explode("\n",$text) as $line)if(str_contains($line,':')){[$k,$v]=explode(':',$line,2);$info[trim($k)]=trim($v);}
        if(strtolower($info['UUID']??'')!==$m['id'])throw new RuntimeException('Missing VM: '.$m['id']);
        $status=match($info['State']??''){'shut off'=>'stopped','running'=>'running','paused'=>'paused','pmsuspended'=>'suspended','in shutdown'=>'stopping',default=>'unknown'};
        $event=is_file($this->store->eventPath($m))?Store::read($this->store->eventPath($m)):[];
        if($status==='stopped' && isset($event['phase']) && $event['phase']!=='release')$status='releasing';
        return ['status'=>$status,'name'=>$info['Name']??$m['id'],'runtimeId'=>$m['id'],'release'=>$event['release']??0,'autostart'=>($info['Autostart']??'')==='enable','serviceIdentity'=>self::serviceIdentity('vm')];
    }
    public function stop(array $m): void
    {
        if($m['type']==='vm'){$this->virsh('shutdown',$m,[],true);return;}
        $ct=$this->docker($m);$signal=strtoupper($ct['Config']['StopSignal']??'SIGTERM');
        // A custom SIGKILL stop signal must not bypass the group's explicit force policy.
        if($signal==='' || in_array($signal,['9','KILL','SIGKILL'],true))$signal='SIGTERM';
        $this->runner->run(['docker','kill','--signal',$signal,'--',$ct['Id']],10,null,true);
    }
    public function forceStop(array $m): void
    {
        if($m['type']==='vm'){$this->virsh('destroy',$m,[],true,30);return;}
        $ct=$this->docker($m);$this->runner->run(['docker','kill','--signal','SIGKILL','--',$ct['Id']],30,null,true);
    }
    public function act(array $m,string $action): void
    {
        if($m['type']==='vm'){
            $command=match($action){'start'=>'start','restart'=>'reboot','resume'=>'resume','wake'=>'dompmwakeup',default=>throw new RuntimeException('Invalid VM action')};
            $this->virsh($command,$m,[],true,60);return;
        }
        $ct=$this->docker($m);
        if(($ct['HostConfig']['NetworkMode']??'')==='host' && ($ct['Config']['Cmd'][0]??'')==='/opt/unraid/tailscale')throw new RuntimeException('Unraid disallows Tailscale-enabled containers in host networking mode');
        $command=match($action){'start'=>'start','resume'=>'unpause',default=>throw new RuntimeException('Invalid container action')};
        $this->runner->run(['docker',$command,'--',$ct['Id']],60,null,true);
        $this->runner->run(['/usr/bin/php',dirname(__DIR__).'/scripts/docker-route.php',$m['id']],10);
    }
    public function inventory(): array
    {
        $items=[];$errors=[];
        foreach(['docker','vm'] as $type){try{
            $text=$type==='docker'?$this->runner->run(['docker','ps','--all','--format','{{.Names}}']):$this->runner->run(['virsh','--connect','qemu:///system','list','--all','--uuid']);
            foreach(array_filter(array_map('trim',explode("\n",$text))) as $id){$m=Config::member(['type'=>$type,'id'=>$id]);try{$items[]=array_merge($m,$this->inspect($m));}catch(\Throwable $e){$items[]=array_merge($m,['name'=>$id,'status'=>'unknown','error'=>$e->getMessage()]);}}
        }catch(\Throwable $e){$errors[$type]=$e->getMessage();}}
        return ['workloads'=>$items,'errors'=>$errors];
    }
    public function console(array $m): array
    {
        if($m['type']!=='vm')throw new RuntimeException('Console requires a VM');
        $xml=$this->virsh('dumpxml',$m);$old=libxml_use_internal_errors(true);
        try{$dom=simplexml_load_string($xml,'SimpleXMLElement',LIBXML_NONET);if(!$dom)throw new RuntimeException('Invalid domain XML');
            foreach($dom->devices->graphics as $g){$type=(string)$g['type'];if(!in_array($type,['vnc','spice'],true))continue;
                $port=(int)$g['port'];$ws=(int)$g['websocket'];if($port<1 || $port>65535 || ($type==='vnc' && ($ws<1 || $ws>65535)))throw new RuntimeException('VM console is not ready');
                return ['protocol'=>$type,'port'=>$port,'websocket'=>$ws];
            }throw new RuntimeException('VM has no VNC or SPICE console');
        }finally{libxml_clear_errors();libxml_use_internal_errors($old);}
    }
}
