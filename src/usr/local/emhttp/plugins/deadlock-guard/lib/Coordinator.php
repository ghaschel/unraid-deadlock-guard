<?php
declare(strict_types=1);
namespace DeadlockGuard;

use RuntimeException;
use Throwable;

final class Coordinator
{
    private const HISTORY_LIMIT = 100;
    private const RESULT_TIMEOUT = 15;
    private array $job = [];

    public function __construct(
        private Store $store,
        private Platform $platform,
        private ?\Closure $ready = null,
    ) {}

    public function run(string $id): void
    {
        $this->claimJob($id);
        $gate = new Gate($this->store);

        try {
            $this->phase('Validating workloads');
            $this->validateTargets();
            foreach ($this->job['plan']['conflicts'] as $member) {
                $this->stop($member, conflict: true);
            }
            foreach ($this->job['plan']['requests'] as $request) {
                $this->executeTarget($request, $gate);
            }
            $this->phase($this->job['handoff'] ? 'Handoff complete' : 'Action complete');
            $this->update(['status' => 'succeeded']);
        } catch (Throwable $error) {
            $this->recordFailure($error);
        } finally {
            foreach ($this->job['plan']['requests'] as $request) {
                if ($request['workload']['type'] === 'vm') {
                    $gate->revoke($request['workload']);
                }
            }
        }
    }

    private function claimJob(string $id): void
    {
        $this->job = $this->store->locked(function () use ($id) {
            $job = $this->store->job($id);
            if ($job['status'] !== 'queued') {
                throw new RuntimeException('Job already claimed');
            }
            $job['status'] = 'running';
            $job['handoff'] = false;
            $job['pid'] = getmypid();
            $job['processIdentity'] = ProcessIdentity::of(getmypid());
            $this->store->putJob($job);
            return $job;
        });
    }

    private function members(): array
    {
        return array_merge(
            array_column($this->job['plan']['requests'], 'workload'),
            $this->job['plan']['conflicts'],
        );
    }

    private function validateTargets(): void
    {
        foreach ($this->members() as $member) {
            if ($this->inspect($member)['status'] === 'unknown') {
                throw new RuntimeException('Unknown state for ' . $member['id']);
            }
        }

        // Validate every target before stopping any conflict in a batch.
        foreach ($this->job['plan']['requests'] as $request) {
            $status = $this->job['states'][Config::key($request['workload'])]['status'];
            $error = match ($request['action']) {
                'start' => in_array($status, ['stopped', 'running'], true)
                    ? null
                    : 'Use Resume for paused/suspended workloads',
                'restart' => $status === 'running' ? null : 'Restart requires a running workload',
                'resume' => $status === 'paused' ? null : 'Resume requires a paused workload',
                'wake' => $status === 'suspended' ? null : 'Wake requires a suspended VM',
            };
            if ($error !== null) {
                throw new RuntimeException($error);
            }
        }
    }

    private function executeTarget(array $request, Gate $gate): void
    {
        // External starts can race the handoff. Re-read conflicts before each target.
        foreach ($this->job['plan']['conflicts'] as $conflict) {
            if ($this->inspect($conflict)['status'] !== 'stopped') {
                throw new RuntimeException('Conflict became active again: ' . $conflict['id']);
            }
        }

        $member = $request['workload'];
        $state = $this->inspect($member);
        $action = $request['action'];
        if ($member['type'] === 'docker' && $action === 'restart') {
            $this->stop($member);
            $action = 'start';
        }
        if ($request['action'] === 'start' && $state['status'] === 'running') {
            return;
        }

        $verb = match ($action) {
            'start' => 'Starting',
            'restart' => 'Restarting',
            'resume' => 'Resuming',
            'wake' => 'Waking',
        };
        $this->phase($verb . ' ' . $state['name']);
        if ($member['type'] === 'vm' && $request['action'] === 'start') {
            $gate->issue($member, $this->job['id']);
        }
        try {
            $this->command(
                $member,
                $request['action'],
                fn() => $this->platform->act($member, $action),
            );
        } finally {
            if ($member['type'] === 'vm') {
                $gate->revoke($member);
            }
        }
        $this->confirmRunning($member);
    }

    private function stop(array $member, bool $conflict = false): void
    {
        $state = $this->inspect($member);
        if ($state['status'] === 'stopped') {
            return;
        }
        if ($state['status'] === 'unknown') {
            throw new RuntimeException(
                'Unknown state for ' . $member['id'] . '; cannot safely hand off',
            );
        }

        if ($conflict) {
            // Restarting the requested container itself does not constitute a handoff.
            $this->update(['handoff' => true]);
        }
        $policy = Config::policy($this->job['config'], $this->job['plan'], $member);
        // An active VM needs a NEW release event, including its first observed release.
        $releaseAfter =
            $member['type'] === 'vm' ? max(0.000001, (float) ($state['release'] ?? 0)) : 0.0;
        $this->phase('Stopping ' . $state['name'], $this->waitingMembers($member));
        $this->command($member, 'stop', fn() => $this->platform->stop($member, $policy['timeout']));
        $this->phase('Waiting for resources to be released', $this->waitingMembers($member));
        // Docker already waited through its stop timeout; only confirmation remains.
        $confirmationTimeout =
            $member['type'] === 'docker' ? self::RESULT_TIMEOUT : $policy['timeout'];
        if ($this->waitStopped($member, $confirmationTimeout, $releaseAfter)) {
            return;
        }
        if ($member['type'] === 'vm' && $policy['force']) {
            $this->phase('Force-stopping ' . $state['name']);
            $this->command($member, 'force-stop', fn() => $this->platform->forceStopVm($member));
            if ($this->waitStopped($member, self::RESULT_TIMEOUT, $releaseAfter)) {
                return;
            }
        }
        throw new RuntimeException(
            'Shutdown timeout for ' . $state['name'] . '; requested workload was not started',
        );
    }

    private function waitingMembers(array $stopping): array
    {
        $waiting = [];
        foreach (array_merge([$stopping], $this->job['plan']['conflicts']) as $member) {
            $key = Config::key($member);
            $state = $this->job['states'][$key];
            if ($member === $stopping || $state['status'] !== 'stopped') {
                $waiting[$key] = ['type' => $member['type'], 'name' => $state['name']];
            }
        }
        return array_values($waiting);
    }

    private function waitStopped(array $member, int $seconds, float $releaseAfter = 0): bool
    {
        $deadline = $this->platform->now() + $seconds;
        do {
            $state = $this->inspect($member);
            $released = $releaseAfter === 0.0 || ($state['release'] ?? 0) > $releaseAfter;
            if ($state['status'] === 'stopped' && $released) {
                return true;
            }
            if ($this->platform->now() >= $deadline) {
                return false;
            }
            $this->platform->pause();
        } while (true);
    }

    private function confirmRunning(array $member): void
    {
        $deadline = $this->platform->now() + self::RESULT_TIMEOUT;
        while ($this->inspect($member)['status'] !== 'running') {
            if ($this->platform->now() >= $deadline) {
                throw new RuntimeException(
                    'Requested workload did not reach running state: ' . $member['id'],
                );
            }
            $this->platform->pause();
        }
    }

    private function command(array $member, string $action, callable $operation): void
    {
        if (is_file($this->store->runDir . '/draining.json')) {
            throw new RuntimeException('Plugin is draining');
        }
        if ($this->ready) {
            ($this->ready)();
        }
        $this->update([
            'inFlight' => ['workload' => $member, 'action' => $action, 'at' => microtime(true)],
        ]);
        $context = [
            'jobId' => $this->job['id'],
            'type' => $member['type'],
            'workloadId' => $member['id'],
            'action' => $action,
        ];
        $this->store->debug->record('command.started', $context);
        try {
            $operation();
            $this->store->debug->record('command.completed', $context);
        } catch (UncertainOperation $error) {
            // Retain intent: an accepted command might still complete after the worker fails.
            throw $error;
        } catch (Throwable $error) {
            $this->update(['inFlight' => null]);
            throw $error;
        }
        $this->update(['inFlight' => null]);
    }

    private function recordFailure(Throwable $error): void
    {
        $this->store->debug->record('job.failed', [
            'jobId' => $this->job['id'],
            'phase' => $this->job['phase'],
            'errorType' => get_class($error),
            'reason' =>
                $this->job['inFlight'] !== null ? 'operation_uncertain' : 'operation_failed',
        ]);
        $uncertain = $error instanceof UncertainOperation || $this->job['inFlight'] !== null;
        foreach ($this->members() as $member) {
            try {
                $this->inspect($member);
            } catch (Throwable $inspectionError) {
                $states = $this->job['states'];
                $states[Config::key($member)] = [
                    'name' => $member['id'],
                    'status' => 'unknown',
                    'error' => $inspectionError->getMessage(),
                ];
                $this->update(['states' => $states]);
            }
        }
        $this->phase(
            $uncertain
                ? 'Operation uncertain — recovery required'
                : ($this->job['handoff']
                    ? 'Handoff failed'
                    : 'Action failed'),
        );
        $this->update([
            'status' => $uncertain ? 'quarantined' : 'failed',
            'error' => $error->getMessage(),
        ]);
    }

    private function inspect(array $member): array
    {
        $state = $this->platform->inspect($member);
        $states = $this->job['states'];
        if (($states[Config::key($member)] ?? null) !== $state) {
            $this->store->debug->record('workload.state', [
                'jobId' => $this->job['id'],
                'type' => $member['type'],
                'workloadId' => $member['id'],
                'runtimeId' => $state['runtimeId'] ?? null,
                'state' => $state['status'],
            ]);
        }
        $states[Config::key($member)] = $state;
        $this->update(['states' => $states]);
        return $state;
    }

    private function phase(string $message, array $workloads = []): void
    {
        $this->store->debug->record('job.phase', [
            'jobId' => $this->job['id'],
            'phase' => $message,
            'handoff' => $this->job['handoff'],
        ]);
        $history = $this->job['history'];
        if ($this->job['handoff']) {
            $history[] = ['at' => microtime(true), 'message' => $message];
        }
        $this->update([
            'phase' => $message,
            'progress' => ['workloads' => $workloads],
            'history' => array_slice($history, -self::HISTORY_LIMIT),
        ]);
        if ($this->job['handoff'] && function_exists('syslog')) {
            syslog(LOG_INFO, 'Deadlock Guard ' . $this->job['id'] . ': ' . $message);
        }
    }

    private function update(array $changes): void
    {
        $this->job = $this->store->updateJob(
            $this->job['id'],
            array_merge($changes, ['updatedAt' => microtime(true)]),
        );
    }
}
