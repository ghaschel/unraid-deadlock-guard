<?php
declare(strict_types=1);
namespace DeadlockGuard;
final class Recovery
{
    public function __construct(private Store $store,private Platform $platform) {}
    public function reconcile(): array
    {
        return $this->store->locked(function(){
            $changed=[];
            foreach($this->store->active() as $j){
                if(ProcessIdentity::alive($j) || $j['status']==='queued')continue;
                // A persisted command intent may have reached Docker/libvirt even when its client died.
                // No timeout, daemon PID change, or observed shutoff proves that request cannot start later.
                if($j['inFlight']!==null){$j['status']='quarantined';$j['error']='An operation may still be in flight. Reservations remain until host reboot; inspect workloads first.';}
                else{
                    try{
                        foreach(array_merge(array_column($j['plan']['requests'],'workload'),$j['plan']['conflicts']) as $m)$j['states'][Config::key($m)]=$this->platform->inspect($m);
                        $j['status']='failed';$j['error']='Worker exited between operations. Current states reconciled; no automatic rollback.';
                    }catch(\Throwable $e){$j['status']='quarantined';$j['error']='Reconciliation unavailable: '.$e->getMessage();}
                }
                foreach($j['plan']['requests'] as $r)if($r['workload']['type']==='vm')(new Gate($this->store))->revoke($r['workload']);
                $j['phase']=$j['status']==='failed'?'Recovered abandoned job':'Recovery required';$j['updatedAt']=microtime(true);$this->store->putJob($j);$changed[]=$j;
            }
            return $changed;
        });
    }
}
