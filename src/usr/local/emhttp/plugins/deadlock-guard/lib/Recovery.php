<?php
declare(strict_types=1);
namespace DeadlockGuard;

final class Recovery
{
    public function __construct(private Store $store, private Platform $platform) {}

    public function reconcile(?string $id = null): array
    {
        return $this->store->locked(function () use ($id) {
            $changed = [];
            foreach ($this->store->active() as $job) {
                if ($id !== null && $job['id'] !== $id) {
                    continue;
                }
                if (ProcessIdentity::alive($job) || $job['status'] === 'queued') {
                    continue;
                }
                // A persisted command intent may have reached Docker/libvirt even when its client died.
                // No timeout, daemon PID change, or observed shutoff proves that request cannot start later.
                if ($job['inFlight'] !== null) {
                    $job['status'] = 'quarantined';
                    $job['error'] =
                        'An operation may still be in flight. Reservations remain until host reboot; inspect workloads first.';
                } else {
                    try {
                        foreach (
                            array_merge(
                                array_column($job['plan']['requests'], 'workload'),
                                $job['plan']['conflicts'],
                            )
                            as $member
                        ) {
                            $job['states'][Config::key($member)] = $this->platform->inspect(
                                $member,
                            );
                        }
                        $job['status'] = 'failed';
                        $job['error'] =
                            'Worker exited between operations. Current states checked; stopped VMs and containers remain stopped.';
                    } catch (\Throwable $error) {
                        $job['status'] = 'quarantined';
                        $job['error'] = 'Unable to check pending job: ' . $error->getMessage();
                    }
                }
                foreach ($job['plan']['requests'] as $r) {
                    if ($r['workload']['type'] === 'vm') {
                        (new Gate($this->store))->revoke($r['workload']);
                    }
                }
                $job['phase'] =
                    $job['status'] === 'failed' ? 'Recovered abandoned job' : 'Recovery required';
                $job['updatedAt'] = microtime(true);
                $this->store->putJob($job);
                $this->store->debug->record('job.recovered', [
                    'jobId' => $job['id'],
                    'status' => $job['status'],
                    'reason' => $job['inFlight'] !== null ? 'command_in_flight' : 'worker_exited',
                ]);
                $changed[] = $job;
            }
            return $changed;
        });
    }
}
