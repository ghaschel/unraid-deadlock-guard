<?php
declare(strict_types=1);
namespace DeadlockGuard;

use RuntimeException;

/** Register a bundled module through Unraid's supported API plugin mechanism. */
final class ApiInstaller
{
    private const NAME = 'unraid-api-plugin-deadlock-guard';
    private string $module;
    private CommandRunner $runner;

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

    public function install(): void
    {
        $this->locked(function () {
            if (!is_file(dirname($this->store->configFile) . '/installed.json')) {
                return;
            }
            $base = $this->root . '/usr/local/unraid-api';
            if (!is_file($base . '/package.json')) {
                throw new RuntimeException('Unraid API is not installed');
            }
            $metadata = Store::read($base . '/package.json');
            if (($metadata['version'] ?? '') !== ApiIntegration::SUPPORTED_VERSION) {
                throw new RuntimeException('This beta requires Unraid API 4.37.4');
            }

            $installed = $base . '/node_modules/' . self::NAME;
            $expected = (new ApiIntegration($this->store, $this->module))->hash();
            try {
                $installedHash = (new ApiIntegration($this->store, $installed))->hash();
            } catch (RuntimeException) {
                $installedHash = '';
            }
            $same =
                $installedHash === $expected && isset($metadata['peerDependencies'][self::NAME]);
            $pending = $this->store->runDir . '/api-archive-pending.json';
            if (!$same) {
                $package = dirname($this->module) . '/api-plugin.tgz';
                if (!is_file($package)) {
                    throw new RuntimeException('Bundled API package is missing');
                }
                Store::atomic($pending, ['pending' => true]);
                $this->runner->run(
                    [
                        'npm',
                        'install',
                        '--prefix',
                        $base,
                        '--offline',
                        '--ignore-scripts',
                        '--save-peer',
                        '--save-exact',
                        '--no-audit',
                        '--no-fund',
                        $package,
                    ],
                    timeout: 120,
                );
                if ((new ApiIntegration($this->store, $installed))->hash() !== $expected) {
                    throw new RuntimeException('Installed API module failed its integrity check');
                }
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
            if (is_file($pending)) {
                $this->runner->run(
                    [$this->root . '/etc/rc.d/rc.unraid-api', 'archive-dependencies'],
                    timeout: 120,
                );
                unlink($pending);
            }
            @unlink($this->store->runDir . '/api-install-error.json');
        });
    }

    /** An API failure must stay visible without disabling working WebUI protection. */
    public function attemptInstall(): void
    {
        try {
            $this->install();
        } catch (\Throwable $error) {
            Store::atomic($this->store->runDir . '/api-install-error.json', [
                'error' => $error->getMessage(),
            ]);
            error_log('Deadlock Guard API integration: ' . $error->getMessage());
        }
    }

    public function remove(): void
    {
        $this->locked(function () {
            if ($this->registered()) {
                $this->runner->run(
                    [
                        $this->root . '/usr/local/bin/unraid-api',
                        'plugins',
                        'remove',
                        self::NAME,
                        '--no-restart',
                    ],
                    timeout: 120,
                );
                if ($this->registered()) {
                    throw new RuntimeException('API module is still registered');
                }
            } else {
                // Recover a partial installation whose registration never completed.
                $base = $this->root . '/usr/local/unraid-api';
                if (
                    is_file($base . '/package.json') &&
                    isset(Store::read($base . '/package.json')['peerDependencies'][self::NAME])
                ) {
                    $this->runner->run(
                        [
                            'npm',
                            'uninstall',
                            '--prefix',
                            $base,
                            '--offline',
                            '--ignore-scripts',
                            '--no-audit',
                            '--no-fund',
                            self::NAME,
                        ],
                        timeout: 120,
                    );
                    $this->runner->run(
                        [$this->root . '/etc/rc.d/rc.unraid-api', 'archive-dependencies'],
                        timeout: 120,
                    );
                }
            }
            @unlink($this->store->runDir . '/api-integration.json');
            @unlink($this->store->runDir . '/api-install-error.json');
        });
    }
}
