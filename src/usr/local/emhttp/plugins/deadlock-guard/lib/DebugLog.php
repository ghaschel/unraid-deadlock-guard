<?php
declare(strict_types=1);
namespace DeadlockGuard;

/** Opt-in, bounded diagnostics. Never let logging affect a command or libvirt hook. */
final class DebugLog
{
    private const MAX_BYTES = 524288;
    private const FIELDS = [
        'op',
        'source',
        'action',
        'type',
        'workloadId',
        'runtimeId',
        'jobId',
        'status',
        'state',
        'phase',
        'stage',
        'reason',
        'errorType',
        'exitCode',
        'durationMs',
        'timeout',
        'mutation',
        'managed',
        'handoff',
        'count',
        'ready',
    ];

    public function __construct(private string $runDir, private string $settingsFile) {}

    public static function system(): self
    {
        return new self(
            '/var/run/deadlock-guard',
            '/boot/config/plugins/deadlock-guard/debug.json',
        );
    }

    public function enabled(): bool
    {
        if (!is_file($this->settingsFile) || is_link($this->settingsFile)) {
            return false;
        }
        $raw = @file_get_contents($this->settingsFile, false, null, 0, 4097);
        if ($raw === false || strlen($raw) > 4096) {
            return false;
        }
        $settings = json_decode($raw, true);
        return is_array($settings) &&
            ($settings['version'] ?? null) === 1 &&
            ($settings['enabled'] ?? null) === true;
    }

    public function setEnabled(mixed $enabled): void
    {
        if (!is_bool($enabled)) {
            throw new \RuntimeException('Debug setting must be a boolean');
        }
        if (!$enabled) {
            $this->record('debug.disabled');
        }
        Store::atomic($this->settingsFile, ['version' => 1, 'enabled' => $enabled]);
        if ($enabled) {
            $this->record('debug.enabled');
        }
    }

    public function record(string $event, array $context = []): void
    {
        $lock = null;
        try {
            if (!$this->enabled() || !preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $event)) {
                return;
            }
            $safe = [];
            foreach (self::FIELDS as $field) {
                $value = $context[$field] ?? null;
                if (is_scalar($value)) {
                    $safe[$field] = is_string($value) ? substr($value, 0, 512) : $value;
                }
            }
            $line =
                json_encode(
                    [
                        'time' => gmdate('Y-m-d\TH:i:s\Z'),
                        'component' => 'php',
                        'pid' => getmypid(),
                        'event' => $event,
                        'context' => $safe,
                    ],
                    JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
                ) . "\n";
            if (strlen($line) > 16384) {
                return;
            }
            if (!is_dir($this->runDir) && !@mkdir($this->runDir, 0700, true)) {
                return;
            }
            $lockPath = $this->runDir . '/debug.lock';
            if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) {
                return;
            }
            $lock = @fopen($lockPath, 'c');
            // In particular, a hook must not wait for a coordinator holding any lock.
            if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
                return;
            }
            @chmod($this->runDir . '/debug.lock', 0600);
            $file = $this->runDir . '/debug.log';
            if (
                is_link($file) ||
                is_link($file . '.1') ||
                (file_exists($file) && !is_file($file))
            ) {
                return;
            }
            clearstatcache(true, $file);
            if (is_file($file) && filesize($file) + strlen($line) > self::MAX_BYTES) {
                if (!@rename($file, $file . '.1')) {
                    return;
                }
            }
            @file_put_contents($file, $line, FILE_APPEND);
            @chmod($file, 0600);
        } catch (\Throwable) {
            // Diagnostic failure must not alter stop/start or authorization decisions.
        } finally {
            if (is_resource($lock)) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
    }

    public function download(): string
    {
        $version =
            trim((string) @file_get_contents(dirname(__DIR__) . '/VERSION')) ?: 'development';
        $os = @parse_ini_file('/etc/unraid-version') ?: [];
        $api = json_decode((string) @file_get_contents('/usr/local/unraid-api/package.json'), true);
        $shownVersion = static fn($value) => is_string($value) &&
        preg_match('/^[0-9][a-zA-Z0-9.+-]{0,63}$/D', $value)
            ? $value
            : 'unavailable';
        $text =
            "Deadlock Guard debug logs\nPlugin: $version\nPHP: " .
            PHP_VERSION .
            "\n" .
            'Unraid: ' .
            $shownVersion($os['version'] ?? null) .
            "\n" .
            'Unraid API: ' .
            $shownVersion($api['version'] ?? null) .
            "\n";
        // Fixed filenames only: this endpoint never accepts a path from the caller.
        foreach (['debug.log.1', 'debug.log', 'debug-api.log.1', 'debug-api.log'] as $name) {
            $file = $this->runDir . '/' . $name;
            if (!is_file($file) || is_link($file)) {
                continue;
            }
            $content = @file_get_contents($file, false, null, 0, self::MAX_BYTES);
            if ($content !== false) {
                $text .= "\n--- $name ---\n" . $content;
            }
        }
        return $text;
    }
}
