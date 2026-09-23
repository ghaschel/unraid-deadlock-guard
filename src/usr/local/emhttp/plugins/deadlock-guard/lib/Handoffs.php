<?php
declare(strict_types=1);
namespace DeadlockGuard;

final class Handoffs
{
    public function __construct(
        private Store $store,
        private Platform $platform,
        private Lifecycle $lifecycle,
        private ?\Closure $launch = null,
    ) {}

    public function submit(
        array $requests,
        string $key,
        string $source = 'webui',
        ?array $allowedTypes = null,
        ?array $batchRequests = null,
    ): array {
        $requests = Config::requests($requests);
        $plan = Config::plan($this->store->config(), $requests, $source);
        Config::assertPermissions($plan, $allowedTypes);
        if ($batchRequests !== null) {
            Config::plan($this->store->config(), $batchRequests, $source);
        }
        // Invalid batches must be rejected before recovery or any queue retry.
        (new PendingJobs($this->store, $this->platform))->check(launchQueued: false);
        $jobs = new Jobs($this->store);
        $existing = $jobs->existing($requests, $key, $source);
        if ($existing !== null) {
            return $this->launchQueued($existing);
        }
        $members = array_merge(array_column($plan['requests'], 'workload'), $plan['conflicts']);
        $needsVmGate = in_array('vm', array_column($members, 'type'), true);
        $this->lifecycle->check(integration: $needsVmGate);
        $this->lifecycle->assertReady($plan);
        foreach ($members as $member) {
            $state = $this->platform->inspect($member);
            if ($state['status'] === 'unknown') {
                throw new \RuntimeException('Unknown state for ' . $member['id']);
            }
        }
        return $this->launchQueued(
            $jobs->submit($requests, $key, $source, $allowedTypes, $batchRequests),
        );
    }

    private function launchQueued(array $job): array
    {
        if ($job['status'] === 'queued' && !is_file($this->store->runDir . '/draining.json')) {
            if ($this->launch) {
                ($this->launch)($job['id']);
            } else {
                Launcher::worker($job['id']);
            }
        }
        return $job;
    }
}
