<?php
declare(strict_types=1);
namespace DeadlockGuard;

use RuntimeException;

final class Jobs
{
    private const MAX_ACTIVE_JOBS = 32;
    private const MAX_IDEMPOTENCY_KEYS = 128;

    public function __construct(private Store $store) {}

    private function fingerprint(array $requests, string $key, string $source): string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{8,128}$/D', $key)) {
            throw new RuntimeException('Invalid idempotency key');
        }
        return hash(
            'sha256',
            json_encode(
                ['source' => Config::source($source), 'requests' => $requests],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    private function findExisting(array $jobs, string $fingerprint, string $key): ?array
    {
        foreach ($jobs as $job) {
            if (in_array($key, $job['keys'], true)) {
                if ($job['fingerprint'] !== $fingerprint) {
                    throw new RuntimeException(
                        'Idempotency key already used for a different request',
                    );
                }
                return $job;
            }
        }
        foreach ($jobs as $job) {
            if (!Store::terminal($job) && $job['fingerprint'] === $fingerprint) {
                if (count($job['keys']) >= self::MAX_IDEMPOTENCY_KEYS) {
                    throw new RuntimeException('Too many duplicate requests');
                }
                $job['keys'][] = $key;
                $this->store->putJob($job);
                return $job;
            }
        }
        return null;
    }

    public function existing(array $requests, string $key, string $source = 'webui'): ?array
    {
        $fingerprint = $this->fingerprint(Config::requests($requests), $key, $source);
        return $this->store->locked(
            fn() => $this->findExisting($this->store->jobs(), $fingerprint, $key),
        );
    }

    public function submit(
        array $requests,
        string $key,
        string $source = 'webui',
        ?array $allowedTypes = null,
        ?array $batchRequests = null,
    ): array {
        $requests = Config::requests($requests);
        $fingerprint = $this->fingerprint($requests, $key, $source);
        return $this->store->locked(function () use (
            $requests,
            $key,
            $fingerprint,
            $source,
            $allowedTypes,
            $batchRequests,
        ) {
            if (is_file($this->store->runDir . '/draining.json')) {
                throw new RuntimeException('Plugin is draining');
            }
            $config = $this->store->config();
            $plan = Config::plan($config, $requests, $source);
            Config::assertPermissions($plan, $allowedTypes);
            if ($batchRequests !== null) {
                Config::plan($config, $batchRequests, $source);
            }
            $touches = array_map(
                [Config::class, 'key'],
                array_merge(array_column($requests, 'workload'), $plan['conflicts']),
            );
            $jobs = $this->store->jobs();
            $existing = $this->findExisting($jobs, $fingerprint, $key);
            if ($existing !== null) {
                return $existing;
            }
            foreach ($jobs as $job) {
                if (
                    !Store::terminal($job) &&
                    (array_intersect($plan['groups'], $job['plan']['groups']) ||
                        array_intersect($touches, $job['touches']))
                ) {
                    throw new RuntimeException('Group is busy: ' . $job['id']);
                }
            }
            if (count($this->store->active()) >= self::MAX_ACTIVE_JOBS) {
                throw new RuntimeException('Too many active handoffs');
            }
            $now = microtime(true);
            $job = [
                'id' => bin2hex(random_bytes(16)),
                'source' => $source,
                'keys' => [$key],
                'fingerprint' => $fingerprint,
                'config' => $config,
                'plan' => $plan,
                'touches' => $touches,
                'status' => 'queued',
                'phase' => 'Queued',
                'createdAt' => $now,
                'updatedAt' => $now,
                'error' => null,
                'history' => [],
                'states' => [],
                'inFlight' => null,
            ];
            $this->store->putJob($job);
            $this->store->prune();
            return $job;
        });
    }

    public static function publicJob(array $job): array
    {
        return array_intersect_key(
            $job,
            array_flip([
                'id',
                'source',
                'status',
                'phase',
                'progress',
                'createdAt',
                'updatedAt',
                'error',
                'history',
                'states',
                'inFlight',
            ]),
        );
    }
}
