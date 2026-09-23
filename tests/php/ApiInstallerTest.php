<?php
use DeadlockGuard\ApiInstaller;
use DeadlockGuard\CommandRunner;
use DeadlockGuard\Store;

function apiInstallerFixture(mixed $version = '4.37.4'): array
{
    $dir = tempdir();
    $store = new Store($dir . '/run', $dir . '/flash/config.json');
    Store::atomic($dir . '/flash/installed.json', ['installed' => true]);
    $base = $dir . '/usr/local/unraid-api';
    mkdir($base, 0755, true);
    Store::atomic($base . '/package.json', ['version' => $version]);
    $module = $dir . '/payload/api-plugin';
    mkdir($module, 0755, true);
    file_put_contents($module . '/index.mjs', 'module');
    file_put_contents(dirname($module) . '/api-plugin.tgz', 'tarball');
    $runner = new class ($dir, $module) implements CommandRunner {
        public array $calls = [];
        public ?string $failCommand = null;
        public function __construct(private string $root, private string $module) {}

        public function run(
            array $argv,
            float $timeout = 10,
            ?string $input = null,
            bool $mutation = false,
        ): string {
            $this->calls[] = $argv;
            if ($this->failCommand !== null && in_array($this->failCommand, $argv, true)) {
                throw new RuntimeException('Simulated setup failure');
            }
            if (in_array('install', $argv, true) && basename($argv[0]) === 'npm') {
                $target =
                    $this->root .
                    '/usr/local/unraid-api/node_modules/unraid-api-plugin-deadlock-guard';
                @mkdir($target, 0755, true);
                copy($this->module . '/index.mjs', $target . '/index.mjs');
                Store::atomic($this->root . '/usr/local/unraid-api/package.json', [
                    'version' => Store::read($this->root . '/usr/local/unraid-api/package.json')[
                        'version'
                    ],
                    'peerDependencies' => [
                        'unraid-api-plugin-deadlock-guard' => 'file:api-plugin.tgz',
                    ],
                ]);
            }
            if (in_array('plugins', $argv, true)) {
                Store::atomic(
                    $this->root . '/boot/config/plugins/dynamix.my.servers/configs/api.json',
                    [
                        'plugins' => in_array('install', $argv, true)
                            ? ['other', 'unraid-api-plugin-deadlock-guard']
                            : ['other'],
                    ],
                );
            }
            return '';
        }
    };
    return [$dir, $store, $module, $runner, new ApiInstaller($store, $runner, $dir, $module)];
}

test(
    'API installation uses local package and native registration without service restart',
    function () {
        [$dir, $store, $module, $runner, $installer] = apiInstallerFixture();
        $installer->install();
        eq(count($runner->calls), 3);
        ok(in_array('--offline', $runner->calls[0], true));
        eq(array_slice($runner->calls[1], 1), [
            'plugins',
            'install',
            'unraid-api-plugin-deadlock-guard',
            '--bundled',
            '--no-restart',
        ]);
        eq(array_slice($runner->calls[2], 1), ['archive-dependencies']);
        $installer->install();
        eq(count($runner->calls), 3);
        file_put_contents($module . '/index.mjs', 'updated module');
        $installer->install();
        eq(count($runner->calls), 5);
        $installer->remove();
        eq(array_slice($runner->calls[5], 1), [
            'plugins',
            'remove',
            'unraid-api-plugin-deadlock-guard',
            '--no-restart',
        ]);
    },
);

test('API installer never registers after uninstall or on unsupported API version', function () {
    [$dir, $store, $module, $runner, $installer] = apiInstallerFixture();
    Store::atomic($dir . '/usr/local/unraid-api/package.json', ['version' => '4.35.1']);
    raises(fn() => $installer->install(), '4.36.0');
    eq($runner->calls, []);
    unlink($dir . '/flash/installed.json');
    $installer->install();
    eq($runner->calls, []);
});

test('API installer repairs an incomplete copy and removes an unregistered package', function () {
    [$dir, $store, $module, $runner, $installer] = apiInstallerFixture();
    mkdir($dir . '/usr/local/unraid-api/node_modules/unraid-api-plugin-deadlock-guard', 0755, true);
    $installer->install();
    eq(count($runner->calls), 3);
    Store::atomic($dir . '/boot/config/plugins/dynamix.my.servers/configs/api.json', [
        'plugins' => ['other'],
    ]);
    $installer->remove();
    ok(in_array('uninstall', $runner->calls[3], true));
    ok(in_array('--offline', $runner->calls[3], true));
    eq(array_slice($runner->calls[4], 1), ['archive-dependencies']);
});

// Exercise installation, not a standalone version parser: incompatible input must
// never invoke npm or register a module, and successful retries clear old errors.
test('API installer accepts compatible versions and repairs the saved setup error', function () {
    $cases = json_decode(file_get_contents(__DIR__ . '/../fixtures/api-versions.json'), true);
    foreach ($cases as $case) {
        [$dir, $store, $module, $runner, $installer] = apiInstallerFixture($case['version']);
        Store::atomic($store->runDir . '/api-install-error.json', [
            'error' => 'Previous rejection',
        ]);
        if ($case['compatible']) {
            $installer->install();
            eq(count($runner->calls), 3);
            ok(!is_file($store->runDir . '/api-install-error.json'));
            $health = (new DeadlockGuard\ApiIntegration($store, $module))->health();
            eq($health['ready'], false);
            ok(str_contains($health['message'], 'Restart'));
        } else {
            raises(fn() => $installer->install(), 'version');
            eq($runner->calls, []);
            ok(is_file($store->runDir . '/api-install-error.json'));
        }
    }
});

test('API setup errors persist through incomplete registration and archive retries', function () {
    foreach (['npm', 'plugins', 'archive-dependencies'] as $failure) {
        [$dir, $store, $module, $runner, $installer] = apiInstallerFixture('4.37.4+ad268301');
        Store::atomic($store->runDir . '/api-install-error.json', [
            'error' => 'Previous rejection',
        ]);
        $runner->failCommand = $failure;
        raises(fn() => $installer->install(), 'Simulated setup failure');
        ok(is_file($store->runDir . '/api-install-error.json'));
        ok(is_file($store->runDir . '/api-archive-pending.json'));
        $runner->failCommand = null;
        $installer->install();
        ok(!is_file($store->runDir . '/api-install-error.json'));
        ok(!is_file($store->runDir . '/api-archive-pending.json'));
    }
});
