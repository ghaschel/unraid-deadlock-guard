<?php
use DeadlockGuard\Config;
use DeadlockGuard\Platform;

// Simulates only Docker/libvirt; coordination and storage remain real.
class SimulatedPlatform implements Platform
{
    public array $states = [],
        $log = [],
        $delays = [],
        $pending = [],
        $refuse = [];
    public float $time = 0;
    public bool $failStart = false,
        $forceWorks = true,
        $releaseWorks = true;
    public function __construct(array $members)
    {
        foreach ($members as $member) {
            $this->states[Config::key($member)] = [
                'status' => 'stopped',
                'name' => $member['id'],
                'runtimeId' => $member['id'],
                'release' => 0,
            ];
        }
    }

    public function inspect(array $member): array
    {
        $key = Config::key($member);
        if (!isset($this->states[$key])) {
            throw new RuntimeException('Missing workload');
        }
        return $this->states[$key];
    }

    public function stop(array $member): void
    {
        $key = Config::key($member);
        $this->log[] = 'stop:' . $key;
        if (empty($this->refuse[$key])) {
            $this->pending[$key] = $this->time + ($this->delays[$key] ?? 0);
        }
    }

    public function forceStop(array $member): void
    {
        $key = Config::key($member);
        $this->log[] = 'force:' . $key;
        if ($this->forceWorks) {
            $this->pending[$key] = $this->time;
        }
    }

    public function act(array $member, string $action): void
    {
        $key = Config::key($member);
        foreach ($this->states as $other => $state) {
            if ($other !== $key && $state['status'] !== 'stopped') {
                throw new RuntimeException('SAFETY: overlapping execution');
            }
        }
        $this->log[] = $action . ':' . $key;
        if ($this->failStart) {
            throw new RuntimeException('Start rejected');
        }
        $this->states[$key]['status'] = 'running';
    }

    public function now(): float
    {
        return $this->time;
    }

    public function pause(): void
    {
        $this->time += 0.25;
        foreach ($this->pending as $key => $deadline) {
            if ($deadline <= $this->time) {
                $this->states[$key]['status'] = 'stopped';
                if ($this->releaseWorks) {
                    $this->states[$key]['release']++;
                }
                unset($this->pending[$key]);
            }
        }
    }
}
