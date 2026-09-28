<?php
declare(strict_types=1);
namespace DeadlockGuard;

use RuntimeException;

/** Register a bundled module through Unraid's supported API plugin mechanism. */
final class ApiInstaller
{
    private const NAME = ApiModuleFiles::NAME;
    private string $module;
    private CommandRunner $runner;
    private string $phase = 'setup';

    public function __construct(
        private Store $store,
        ?CommandRunner $runner = null,
        private string $root = '',
        ?string $module = null,
    ) {
        $this->runner = $runner ?? new Runner();
        $this->module = $module ?? dirname(__DIR__) . '/api-plugin';
    }

    private function locked(callable $action): void
    {
        $lock = fopen($this->store->runDir . '/api-install.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Cannot lock API installation');
        }
        try {
            $action();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function registered(): bool
    {
        $path = $this->root . '/boot/config/plugins/dynamix.my.servers/configs/api.json';
        return is_file($path) && in_array(self::NAME, Store::read($path)['plugins'] ?? [], true);
    }

    public function install(bool $restart = false): void
    {
        $this->phase = 'setup';
        $this->store->debug->record('api.install_started');
        $this->locked(function () use ($restart) {
            if (!is_file(dirname($this->store->configFile) . '/installed.json')) {
                return;
            }
            $base = $this->root . '/usr/local/unraid-api';
            if (!is_file($base . '/package.json')) {
                throw new RuntimeException('Unraid API is not installed');
            }
            $metadata = Store::read($base . '/package.json');
            if ($versionError = ApiVersion::error($metadata['version'] ?? null)) {
                throw new RuntimeException($versionError);
            }

            $files = new ApiModuleFiles($base, $this->module);
            $pending = $this->store->runDir . '/api-archive-pending.json';
            if (!$files->matches()) {
                Store::atomic($pending, ['pending' => true]);
                $files->install();
            }
            if (!$this->registered()) {
                Store::atomic($pending, ['pending' => true]);
                $this->runner->run(
                    [
                        $this->root . '/usr/local/bin/unraid-api',
                        'plugins',
                        'install',
                        self::NAME,
                        '--bundled',
                        '--no-restart',
                    ],
                    timeout: 60,
                );
                if (!$this->registered()) {
                    throw new RuntimeException('API plugin registration did not complete');
                }
            }
            $this->archivePending();
            if ($restart) {
                $this->restartApi();
            }
            $errorFile = $this->store->runDir . '/api-install-error.json';
            $previousError = is_file($errorFile) ? Store::read($errorFile) : [];
            // Routine restoration cannot resolve a failed restart. Keep that error
            // until a full setup-and-restart retry succeeds.
            if ($restart || ($previousError['stage'] ?? '') !== 'restart') {
                @unlink($errorFile);
            }
        });
    }

    /** An API failure must stay visible without disabling working WebUI protection. */
    public function attemptInstall(bool $restart = false): bool
    {
        try {
            $this->install($restart);
            $this->store->debug->record('api.install_completed');
            return true;
        } catch (\Throwable $error) {
            $this->store->debug->record('api.install_failed', ['errorType' => get_class($error)]);
            Store::atomic($this->store->runDir . '/api-install-error.json', [
                'error' => $error->getMessage(),
                'stage' => $this->phase,
            ]);
            error_log('Deadlock Guard API integration: ' . $error->getMessage());
            return false;
        }
    }

    /** Only the final manifest step requests a restart, after setup has succeeded. */
    private function restartApi(): void
    {
        $this->phase = 'restart';
        $this->store->debug->record('api.restart_started');
        try {
            $this->runner->run(
                [$this->root . '/usr/local/bin/unraid-api', 'restart'],
                timeout: 120,
            );
        } catch (\Throwable $error) {
            $this->store->debug->record('api.restart_failed', ['errorType' => get_class($error)]);
            throw new RuntimeException(
                'Unraid API restart failed. Run /usr/local/emhttp/plugins/deadlock-guard/scripts/lifecycle api-install to retry setup and restart. ' .
                    $error->getMessage(),
                0,
                $error,
            );
        }
        $this->store->debug->record('api.restart_completed');
    }

    private function archivePending(): void
    {
        $pending = $this->store->runDir . '/api-archive-pending.json';
        if (!is_file($pending)) {
            return;
        }
        $this->runner->run(
            [$this->root . '/etc/rc.d/rc.unraid-api', 'archive-dependencies'],
            timeout: 120,
        );
        unlink($pending);
    }

    public function remove(): void
    {
        $this->locked(function () {
            $files = new ApiModuleFiles($this->root . '/usr/local/unraid-api', $this->module);
            $files->assertSafe();
            $registered = $this->registered();
            if ($registered || $files->present()) {
                Store::atomic($this->store->runDir . '/api-archive-pending.json', [
                    'pending' => true,
                ]);
            }
            if ($registered) {
                $this->runner->run(
                    [
                        $this->root . '/usr/local/bin/unraid-api',
                        'plugins',
                        'remove',
                        self::NAME,
                        '--bypass-npm',
                        '--no-restart',
                    ],
                    timeout: 60,
                );
                if ($this->registered()) {
                    throw new RuntimeException('API module is still registered');
                }
            }
            $files->remove();
            $this->archivePending();
            @unlink($this->store->runDir . '/api-integration.json');
            @unlink($this->store->runDir . '/api-install-error.json');
        });
    }
}
