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
    file_put_contents($module . '/index.mjs', 'export const version = 1;');
    Store::atomic($module . '/package.json', [
        'name' => 'unraid-api-plugin-deadlock-guard',
        'version' => '0.1.0',
        'type' => 'module',
        'main' => 'index.mjs',
    ]);
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
            if (basename($argv[0]) === 'npm') {
                throw new RuntimeException('Installer must not run npm on host dependencies');
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
    'API installation copies only its bundled module and uses native registration without service restart',
    function () {
        [$dir, $store, $module, $runner, $installer] = apiInstallerFixture();
        $installer->install();
        eq(count($runner->calls), 2);
        eq(array_slice($runner->calls[0], 1), [
            'plugins',
            'install',
            'unraid-api-plugin-deadlock-guard',
            '--bundled',
            '--no-restart',
        ]);
        eq(array_slice($runner->calls[1], 1), ['archive-dependencies']);
        $installer->install();
        eq(count($runner->calls), 2);
        file_put_contents($module . '/index.mjs', 'updated module');
        $installer->install();
        eq(count($runner->calls), 3);
        $installer->remove();
        eq(array_slice($runner->calls[3], 1), [
            'plugins',
            'remove',
            'unraid-api-plugin-deadlock-guard',
            '--bypass-npm',
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
    eq(count($runner->calls), 2);
    Store::atomic($dir . '/boot/config/plugins/dynamix.my.servers/configs/api.json', [
        'plugins' => ['other'],
    ]);
    $installer->remove();
    eq(array_slice($runner->calls[2], 1), ['archive-dependencies']);
    ok(!file_exists($dir . '/usr/local/unraid-api/node_modules/unraid-api-plugin-deadlock-guard'));
    ok(
        !isset(
            Store::read($dir . '/usr/local/unraid-api/package.json')['peerDependencies'][
                'unraid-api-plugin-deadlock-guard'
            ],
        ),
    );
});

// Exercise installation, not a standalone version parser: incompatible input must
// never invoke npm or register a module, and successful retries clear old errors.
test('API installer accepts compatible versions and repairs the saved setup error', function () {
    $cases = json_decode(file_get_contents(__DIR__ . '/../fixtures/api-versions.json'), true);
    foreach ($cases as $case) {
        [$dir, $store, $module, $runner, $installer] = apiInstallerFixture($case['version']);
        Store::atomic($store->runDir . '/api-install-error.json', [
            'error' => 'This beta requires Unraid API 4.37.4',
        ]);
        if ($case['compatible']) {
            $installer->install();
            eq(count($runner->calls), 2);
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
    foreach (['plugins', 'archive-dependencies'] as $failure) {
        [$dir, $store, $module, $runner, $installer] = apiInstallerFixture('4.37.4+ad268301');
        Store::atomic($store->runDir . '/api-install-error.json', [
            'error' => 'This beta requires Unraid API 4.37.4',
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

test('API install update and remove preserve host dependencies and other plugins', function () {
    [$dir, $store, $module, $runner, $installer] = apiInstallerFixture();
    $base = $dir . '/usr/local/unraid-api';
    $original = (object) [
        'version' => '4.37.4+ad268301',
        'dependencies' => (object) ['@apollo/server' => '5.5.1'],
        'peerDependencies' => (object) ['other-plugin' => '1.2.3'],
        'overrides' => (object) ['brace-expansion' => '5.0.9'],
        'scripts' => (object) [],
    ];
    file_put_contents($base . '/package.json', json_encode($original));
    chmod($base . '/package.json', 0644);
    file_put_contents($base . '/package-lock.json', 'preserve exact lockfile bytes');
    mkdir($base . '/node_modules/other-plugin', 0755, true);
    file_put_contents($base . '/node_modules/other-plugin/index.js', 'other plugin');
    $target = $base . '/node_modules/unraid-api-plugin-deadlock-guard';
    $installer->install();
    eq(file_get_contents($target . '/index.mjs'), file_get_contents($module . '/index.mjs'));
    eq(file_get_contents($target . '/package.json'), file_get_contents($module . '/package.json'));
    file_put_contents($target . '/obsolete.mjs', 'old module');
    file_put_contents($module . '/index.mjs', 'export const version = 2;');
    $installer->install();
    eq(file_get_contents($target . '/index.mjs'), file_get_contents($module . '/index.mjs'));
    ok(!file_exists($target . '/obsolete.mjs'));
    // API replacement can restore root metadata while retaining archived modules.
    file_put_contents($base . '/package.json', json_encode($original));
    $installer->install();
    ok(
        isset(
            Store::read($base . '/package.json')['peerDependencies'][
                'unraid-api-plugin-deadlock-guard'
            ],
        ),
    );
    $installer->remove();
    eq(
        json_encode(json_decode(file_get_contents($base . '/package.json'))),
        json_encode($original),
    );
    eq(fileperms($base . '/package.json') & 0777, 0644);
    eq(file_get_contents($base . '/package-lock.json'), 'preserve exact lockfile bytes');
    eq(file_get_contents($base . '/node_modules/other-plugin/index.js'), 'other plugin');
    ok(!file_exists($target));
    eq(Store::read($dir . '/boot/config/plugins/dynamix.my.servers/configs/api.json')['plugins'], [
        'other',
    ]);
});

test('API removal retries its archive after unregistering and removing module files', function () {
    [$dir, $store, $module, $runner, $installer] = apiInstallerFixture();
    $installer->install();
    $runner->failCommand = 'archive-dependencies';
    raises(fn() => $installer->remove(), 'Simulated setup failure');
    ok(is_file($store->runDir . '/api-archive-pending.json'));
    $runner->failCommand = null;
    $before = count($runner->calls);
    $installer->remove();
    eq(array_slice($runner->calls[$before], 1), ['archive-dependencies']);
    ok(!is_file($store->runDir . '/api-archive-pending.json'));
});

test('API installation and removal refuse a linked module directory', function () {
    [$dir, $store, $module, $runner, $installer] = apiInstallerFixture();
    $base = $dir . '/usr/local/unraid-api';
    mkdir($base . '/node_modules');
    symlink($module, $base . '/node_modules/unraid-api-plugin-deadlock-guard');
    raises(fn() => $installer->install(), 'symbolic link');
    raises(fn() => $installer->remove(), 'symbolic link');
    ok(is_file($module . '/index.mjs'));
    eq($runner->calls, []);
});

test(
    'missing Unraid API records diagnostics without blocking installation or WebUI handoffs',
    function () {
        [$dir, $store, $module, $runner, $installer] = apiInstallerFixture();
        unlink($dir . '/usr/local/unraid-api/package.json');
        rmdir($dir . '/usr/local/unraid-api');
        $members = [member('docker', 'a'), member('docker', 'b')];
        $store->saveConfig(config([group('gpu', $members)]));
        $previousLog = ini_set('error_log', $dir . '/install.log');
        try {
            $installer->attemptInstall(restart: true);
        } finally {
            ini_set('error_log', $previousLog);
        }
        eq($runner->calls, []);
        ok(is_file($dir . '/flash/installed.json'));
        $health = (new DeadlockGuard\ApiIntegration($store, $module))->health();
        eq($health['ready'], false);
        eq($health['details'], 'Unraid API is not installed');
        eq(count($store->config()['groups']), 1);
        $platform = new SimulatedPlatform($members);
        $platform->states['docker:a']['status'] = 'running';
        $launched = [];
        $job = readyHandoffs($store, $platform, $launched)->submit(
            [['workload' => $members[1], 'action' => 'start']],
            'without-api',
        );
        (new DeadlockGuard\Coordinator($store, $platform))->run($job['id']);
        eq($store->job($job['id'])['status'], 'succeeded');
        eq($platform->states['docker:a']['status'], 'stopped');
        eq($platform->states['docker:b']['status'], 'running');
    },
);

test(
    'final plugin installation restarts the API once after registration and archiving',
    function () {
        [$dir, $store, $module, $runner, $installer] = apiInstallerFixture();
        // Package extraction prepares the module; the manifest completes activation.
        $installer->install();
        $installer->install(restart: true);
        eq(count($runner->calls), 3);
        eq($runner->calls[2], [$dir . '/usr/local/bin/unraid-api', 'restart']);

        // Even an unchanged reinstall must load the module without a manual command.
        $installer->install(restart: true);
        eq(count($runner->calls), 4);
        eq($runner->calls[3], [$dir . '/usr/local/bin/unraid-api', 'restart']);

        file_put_contents($module . '/index.mjs', 'updated API module');
        $installer->install(restart: true);
        eq(array_slice($runner->calls[4], 1), ['archive-dependencies']);
        eq($runner->calls[5], [$dir . '/usr/local/bin/unraid-api', 'restart']);
    },
);

test('failed API setup and uninstalled plugins never restart the API', function () {
    foreach (['plugins', 'archive-dependencies'] as $failure) {
        [$dir, $store, $module, $runner, $installer] = apiInstallerFixture();
        $runner->failCommand = $failure;
        raises(fn() => $installer->install(restart: true), 'Simulated setup failure');
        foreach ($runner->calls as $call) {
            ok(!in_array('restart', $call, true));
        }
    }
    [$dir, $store, $module, $runner, $installer] = apiInstallerFixture('4.35.1');
    raises(fn() => $installer->install(restart: true), '4.36.0');
    eq($runner->calls, []);
    unlink($dir . '/flash/installed.json');
    $installer->install(restart: true);
    eq($runner->calls, []);
});

test('API restart failures remain visible and successful retries clear the error', function () {
    [$dir, $store, $module, $runner, $installer] = apiInstallerFixture();
    $runner->failCommand = 'restart';
    $previousLog = ini_set('error_log', $dir . '/restart.log');
    try {
        $installer->attemptInstall(restart: true);
    } finally {
        ini_set('error_log', $previousLog);
    }
    $health = (new DeadlockGuard\ApiIntegration($store, $module))->health();
    eq($health['ready'], false);
    ok(str_contains($health['details'], 'Unraid API restart failed'));
    ok(str_contains($health['details'], 'scripts/lifecycle api-install'));
    ok(is_file($dir . '/flash/installed.json'));
    ok(
        is_file(
            $dir . '/usr/local/unraid-api/node_modules/unraid-api-plugin-deadlock-guard/index.mjs',
        ),
    );
    $runner->failCommand = null;
    $before = count($runner->calls);
    $installer->install();
    eq(count($runner->calls), $before);
    ok(is_file($store->runDir . '/api-install-error.json'));
    eq((new DeadlockGuard\ApiIntegration($store, $module))->health()['ready'], false);
    // This is also the path used by the documented lifecycle api-install retry.
    $installer->install(restart: true);
    ok(!is_file($store->runDir . '/api-install-error.json'));
    eq($runner->calls[array_key_last($runner->calls)], [
        $dir . '/usr/local/bin/unraid-api',
        'restart',
    ]);
});
