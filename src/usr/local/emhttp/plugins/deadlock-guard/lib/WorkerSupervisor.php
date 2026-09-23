<?php
declare(strict_types=1);
namespace DeadlockGuard;

final class WorkerSupervisor
{
    public function __construct(
        private Store $store,
        private Platform $platform,
        private ?string $workerScript = null,
    ) {}

    public function run(string $id): void
    {
        $claimed = $this->store->locked(function () use ($id) {
            $job = $this->store->job($id);
            if ($job['status'] !== 'queued' || ProcessIdentity::alive($job['supervisor'] ?? null)) {
                return false;
            }
            $identity = ProcessIdentity::of(getmypid());
            if ($identity === null) {
                throw new \RuntimeException('Cannot identify the worker supervisor');
            }
            $job['supervisor'] = ['pid' => getmypid(), 'processIdentity' => $identity];
            $this->store->putJob($job);
            return true;
        });
        if (!$claimed) {
            return;
        }
        $failure = 'Worker exited before starting the handoff.';
        try {
            // Block on this child only. There is no idle daemon, polling loop or cron.
            $argv = [
                PHP_BINARY,
                '-d',
                'auto_prepend_file=',
                $this->workerScript ?? dirname(__DIR__) . '/scripts/cli.php',
                'worker',
                $id,
            ];
            $process = proc_open(
                $argv,
                [
                    ['file', '/dev/null', 'r'],
                    ['file', '/dev/null', 'w'],
                    ['file', '/dev/null', 'w'],
                ],
                $pipes,
            );
            if (!is_resource($process)) {
                throw new \RuntimeException('Could not launch the handoff worker');
            }
            $exit = proc_close($process);
            $failure .= ' Exit status: ' . $exit . '.';
        } catch (\Throwable $error) {
            $failure = $error->getMessage();
        }
        // A queued job has not issued commands. Only the supervisor that reaped its child
        // may fail it here; a running job instead needs the normal fail-closed recovery.
        $this->store->locked(function () use ($id, $failure) {
            if (!is_file($this->store->jobPath($id))) {
                return;
            } // completed history may have been pruned
            $job = $this->store->job($id);
            if ($job['status'] === 'queued') {
                $job['status'] = 'failed';
                $job['phase'] = 'Worker could not start';
                $job['error'] = $failure;
                $job['updatedAt'] = microtime(true);
                $this->store->putJob($job);
            }
        });
        (new Recovery($this->store, $this->platform))->reconcile($id);
    }
}
