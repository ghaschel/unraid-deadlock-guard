<?php
declare(strict_types=1);
namespace DeadlockGuard;
use RuntimeException;
final class Coordinator
{
    private array $job=[];
    public function __construct(private Store $store,private Platform $platform, private ?\Closure $ready=null) {}
    private function update(array $changes): void
    {
        $this->job=$this->store->updateJob($this->job['id'],array_merge($changes,['updatedAt'=>microtime(true)]));
    }
    private function phase(string $message): void
    {
        $history=$this->job['history'];$history[]=['at'=>microtime(true),'message'=>$message];
        $this->update(['phase'=>$message,'history'=>array_slice($history,-100)]);
        if(function_exists('syslog'))syslog(LOG_INFO,'Deadlock Guard '.$this->job['id'].': '.$message);
    }
    private function inspect(array $m): array
    {
        $s=$this->platform->inspect($m);$states=$this->job['states'];$states[Config::key($m)]=$s;$this->update(['states'=>$states]);return $s;
    }
    private function waitStopped(array $m,int $seconds,float $releaseAfter=0): bool
    {
        $end=$this->platform->now()+$seconds;
        do{
            $state=$this->inspect($m);
            if($state['status']==='stopped' && ($releaseAfter===0.0 || ($state['release']??0)>$releaseAfter))return true;
            if($this->platform->now()>=$end)return false;
            $this->platform->pause();
        }while(true);
    }
    private function command(array $m,string $action,callable $fn): void
    {
        if(is_file($this->store->runDir.'/draining.json'))throw new RuntimeException('Plugin is draining');
        if($this->ready)($this->ready)();
        $this->update(['inFlight'=>['workload'=>$m,'action'=>$action,'at'=>microtime(true)]]);
        try{$fn();}catch(UncertainOperation $e){throw $e;}catch(\Throwable $e){$this->update(['inFlight'=>null]);throw $e;}
        $this->update(['inFlight'=>null]);
    }
    private function stop(array $m): void
    {
        $state=$this->inspect($m);if($state['status']==='stopped')return;
        if($state['status']==='unknown')throw new RuntimeException('Unknown state for '.$m['id'].'; cannot safely hand off');
        $policy=Config::policy($this->job['config'],$this->job['plan'],$m);
        // A VM seen active needs a NEW release event, including the first observed release.
        $releaseAfter=$m['type']==='vm'?max(0.000001,(float)($state['release']??0)):0.0;
        $this->phase('Stopping '.$state['name']);
        $this->command($m,'stop',fn()=>$this->platform->stop($m));
        $this->phase('Waiting for resource release: '.$state['name']);
        if($this->waitStopped($m,$policy['timeout'],$releaseAfter))return;
        if($policy['force']){
            $this->phase('Force-stopping '.$state['name']);
            $this->command($m,'force-stop',fn()=>$this->platform->forceStop($m));
            if($this->waitStopped($m,15,$releaseAfter))return;
        }
        throw new RuntimeException('Shutdown timeout for '.$state['name'].'; requested workload was not started');
    }
    public function run(string $id): void
    {
        $this->job=$this->store->locked(function()use($id){
            $j=$this->store->job($id);if($j['status']!=='queued')throw new RuntimeException('Job already claimed');
            $j['status']='running';$j['pid']=getmypid();$j['processIdentity']=ProcessIdentity::of(getmypid());$this->store->putJob($j);return $j;
        });
        $gate=new Gate($this->store);
        try{
            $this->phase('Validating workloads');
            $members=array_merge(array_column($this->job['plan']['requests'],'workload'),$this->job['plan']['conflicts']);
            foreach($members as $m){$s=$this->inspect($m);if($s['status']==='unknown')throw new RuntimeException('Unknown state for '.$m['id']);}
            // Reject invalid target actions before stopping any conflicts.
            foreach($this->job['plan']['requests'] as $r){$s=$this->job['states'][Config::key($r['workload'])]['status'];
                if($r['action']==='start' && !in_array($s,['stopped','running'],true))throw new RuntimeException('Use Resume for paused/suspended workloads');
                if($r['action']==='restart' && $s!=='running')throw new RuntimeException('Restart requires a running workload');
                if($r['action']==='resume' && $s!=='paused')throw new RuntimeException('Resume requires a paused workload');
                if($r['action']==='wake' && $s!=='suspended')throw new RuntimeException('Wake requires a suspended VM');
            }
            foreach($this->job['plan']['conflicts'] as $m)$this->stop($m);
            foreach($this->job['plan']['requests'] as $r){
                foreach($this->job['plan']['conflicts'] as $m)if($this->inspect($m)['status']!=='stopped')throw new RuntimeException('Conflict became active again: '.$m['id']);
                $m=$r['workload'];$s=$this->inspect($m);
                $action=$r['action'];
                if($m['type']==='docker' && $action==='restart'){$this->stop($m);$action='start';}
                if($r['action']==='start' && $s['status']==='running')continue;
                $this->phase((['start'=>'Starting','restart'=>'Restarting','resume'=>'Resuming','wake'=>'Waking'][$action]).' '.$s['name']);
                if($m['type']==='vm' && $r['action']==='start')$gate->issue($m,$id);
                try{$this->command($m,$r['action'],fn()=>$this->platform->act($m,$action));}finally{if($m['type']==='vm')$gate->revoke($m);}
                $end=$this->platform->now()+15;
                while($this->inspect($m)['status']!=='running'){
                    if($this->platform->now()>=$end)throw new RuntimeException('Requested workload did not reach running state: '.$m['id']);
                    $this->platform->pause();
                }
            }
            $this->phase('Handoff complete');$this->update(['status'=>'succeeded']);
        }catch(\Throwable $e){
            $uncertain=$e instanceof UncertainOperation || $this->job['inFlight']!==null;
            foreach(array_merge(array_column($this->job['plan']['requests'],'workload'),$this->job['plan']['conflicts']) as $member){
                try{$this->inspect($member);}catch(\Throwable $inspection){$states=$this->job['states'];$states[Config::key($member)]=['name'=>$member['id'],'status'=>'unknown','error'=>$inspection->getMessage()];$this->update(['states'=>$states]);}
            }
            $this->phase($uncertain?'Operation uncertain — recovery required':'Handoff failed');
            $this->update(['status'=>$uncertain?'quarantined':'failed','error'=>$e->getMessage()]);
        }finally{
            foreach($this->job['plan']['requests'] as $r)if($r['workload']['type']==='vm')$gate->revoke($r['workload']);
        }
    }
}
