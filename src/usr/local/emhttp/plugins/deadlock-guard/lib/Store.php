<?php
declare(strict_types=1);
namespace DeadlockGuard;

use RuntimeException;

final class Store
{
    private const MAX_COMPLETED_JOBS = 200;

    public function __construct(public readonly string $runDir, public readonly string $configFile)
    {
        foreach (
            [$runDir, $runDir . '/jobs', $runDir . '/permissions', $runDir . '/events']
            as $dir
        ) {
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create runtime directory');
            }
            chmod($dir, 0700);
        }
    }

    public static function system(): self
    {
        return new self(
            '/var/run/deadlock-guard',
            '/boot/config/plugins/deadlock-guard/config.json',
        );
    }

    public function locked(callable $operation): mixed
    {
        $handle = fopen($this->runDir . '/registry.lock', 'c');
        if (!$handle || !flock($handle, LOCK_EX)) {
            throw new RuntimeException('Cannot lock job registry');
        }
        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public static function read(string $path): array
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new RuntimeException('Cannot read ' . basename($path));
        }
        $record = json_decode($data, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($record)) {
            throw new RuntimeException('Invalid JSON record');
        }
        return $record;
    }

    public static function atomic(string $path, array $value): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create storage directory');
        }
        $temporary = tempnam($dir, '.write-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create temporary record');
        }
        try {
            chmod($temporary, 0600);
            $handle = fopen($temporary, 'wb');
            if (!$handle) {
                throw new RuntimeException('Cannot open temporary record');
            }
            try {
                $bytes =
                    json_encode(
                        $value,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                    ) . "\n";
                if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                    throw new RuntimeException('Cannot write complete record');
                }
                if (function_exists('fsync')) {
                    fsync($handle);
                }
            } finally {
                fclose($handle);
            }
            if (!rename($temporary, $path)) {
                throw new RuntimeException('Cannot publish record');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function config(): array
    {
        try {
            return Config::validate(self::read($this->configFile));
        } catch (\Throwable $error) {
            throw new RuntimeException(
                'Cannot load configuration: ' . $error->getMessage(),
                0,
                $error,
            );
        }
    }

    public function saveConfig(array $config, ?string $expectedRevision = null): array
    {
        $config = Config::validate($config);
        return $this->locked(function () use ($config, $expectedRevision) {
            if ($this->active()) {
                throw new RuntimeException(
                    'Cannot edit configuration while handoffs are active or quarantined',
                );
            }
            if ($expectedRevision !== null && $expectedRevision !== $this->revision()) {
                throw new RuntimeException('Configuration changed; reload before saving');
            }
            self::atomic($this->configFile, $config);
            return $config;
        });
    }

    public function revision(): string
    {
        return hash('sha256', json_encode($this->config(), JSON_THROW_ON_ERROR));
    }

    public function jobPath(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new RuntimeException('Invalid job ID');
        }
        return $this->runDir . '/jobs/' . $id . '.json';
    }

    public function job(string $id): array
    {
        return self::read($this->jobPath($id));
    }

    public function jobs(): array
    {
        $jobs = [];
        foreach (glob($this->runDir . '/jobs/*.json') ?: [] as $path) {
            $jobs[] = self::read($path);
        }
        usort($jobs, fn($a, $b) => $b['createdAt'] <=> $a['createdAt']);
        return $jobs;
    }

    public static function terminal(array $job): bool
    {
        return in_array($job['status'], ['succeeded', 'failed'], true);
    }

    public function active(): array
    {
        return array_values(array_filter($this->jobs(), fn($job) => !self::terminal($job)));
    }

    public function putJob(array $job): void
    {
        self::atomic($this->jobPath($job['id']), $job);
    }

    public function updateJob(string $id, array $changes): array
    {
        return $this->locked(function () use ($id, $changes) {
            $job = array_replace($this->job($id), $changes);
            $this->putJob($job);
            return $job;
        });
    }

    public function prune(): void
    {
        $completedCount = 0;
        foreach ($this->jobs() as $job) {
            if (self::terminal($job) && ++$completedCount > self::MAX_COMPLETED_JOBS) {
                unlink($this->jobPath($job['id']));
            }
        }
    }

    public function eventPath(array $member): string
    {
        return $this->runDir . '/events/' . hash('sha256', Config::key($member)) . '.json';
    }

    public function permissionPath(array $member): string
    {
        return $this->runDir . '/permissions/' . hash('sha256', Config::key($member)) . '.json';
    }
}
