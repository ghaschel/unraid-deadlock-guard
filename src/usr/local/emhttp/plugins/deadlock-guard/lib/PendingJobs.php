<?php
declare(strict_types=1);
namespace DeadlockGuard;

final class PendingJobs
{
    public function __construct(
        private Store $store,
        private Platform $platform,
        private ?\Closure $launch = null,
    ) {}

    public function check(bool $launchQueued = true): array
    {
        $changed = (new Recovery($this->store, $this->platform))->reconcile();
        if ($launchQueued && !is_file($this->store->runDir . '/draining.json')) {
            foreach ($this->store->active() as $j) {
                if ($j['status'] === 'queued') {
                    if ($this->launch) {
                        ($this->launch)($j['id']);
                    } else {
                        Launcher::worker($j['id']);
                    }
                }
            }
        }
        return $changed;
    }
}
