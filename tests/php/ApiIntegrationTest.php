<?php
use DeadlockGuard\ApiIntegration;
use DeadlockGuard\ProcessIdentity;
use DeadlockGuard\Store;

test('API health requires a live adapter with matching installed code', function () {
    $dir = tempdir();
    $store = new Store($dir . '/run', $dir . '/config');
    $module = $dir . '/module';
    mkdir($module);
    file_put_contents($module . '/index.mjs', 'export const adapter="nestjs";');
    $integration = new ApiIntegration($store, $module);
    eq($integration->health()['ready'], false);
    $record = [
        'pid' => getmypid(),
        'processIdentity' => ProcessIdentity::of(getmypid()),
        'hash' => $integration->hash(),
        'apiVersion' => '4.37.4',
    ];
    Store::atomic($store->runDir . '/api-integration.json', $record);
    eq($integration->health()['ready'], true);
    $integration->assertReady($record['hash']);
    raises(fn() => $integration->assertReady(str_repeat('0', 64)), 'adapter');
    file_put_contents($module . '/index.mjs', 'export const adapter="changed";');
    eq($integration->health()['ready'], false);
    Store::atomic(
        $store->runDir . '/api-integration.json',
        array_replace($record, ['pid' => 99999999]),
    );
    eq($integration->health()['ready'], false);
});

test(
    'API health accepts build metadata and newer compatible versions without losing diagnostics',
    function () {
        $dir = tempdir();
        $store = new Store($dir . '/run', $dir . '/config');
        $module = $dir . '/module';
        mkdir($module);
        file_put_contents($module . '/index.mjs', 'module');
        $integration = new ApiIntegration($store, $module);
        $cases = json_decode(file_get_contents(__DIR__ . '/../fixtures/api-versions.json'), true);
        foreach ($cases as $case) {
            Store::atomic($store->runDir . '/api-integration.json', [
                'pid' => getmypid(),
                'processIdentity' => ProcessIdentity::of(getmypid()),
                'hash' => $integration->hash(),
                'apiVersion' => $case['version'],
            ]);
            $health = $integration->health();
            eq($health['ready'], $case['compatible']);
            if (!$case['compatible'] && is_string($case['version']) && $case['version'] !== '') {
                ok(str_contains($health['details'], $case['version']));
            }
        }
    },
);

test(
    'API version failures show a concise requirement and retain detected-version details',
    function () {
        $dir = tempdir();
        $store = new Store($dir . '/run', $dir . '/config');
        $module = $dir . '/module';
        mkdir($module);
        file_put_contents($module . '/index.mjs', 'module');
        $integration = new ApiIntegration($store, $module);
        $error = DeadlockGuard\ApiVersion::error('4.35.9+hostbuild');
        foreach (['installer', 'runtime', 'health'] as $stage) {
            $record = [
                'pid' => getmypid(),
                'processIdentity' => ProcessIdentity::of(getmypid()),
                'hash' => $integration->hash(),
                'apiVersion' => '4.35.9+hostbuild',
            ];
            if ($stage === 'installer') {
                Store::atomic($store->runDir . '/api-install-error.json', ['error' => $error]);
            } else {
                if ($stage === 'runtime') {
                    $record['error'] = $error;
                }
                Store::atomic($store->runDir . '/api-integration.json', $record);
            }
            $health = $integration->health();
            eq($health['ready'], false);
            eq($health['message'], 'Unraid API 4.36.0 or newer is required. See Troubleshooting.');
            ok(str_contains($health['details'], '4.35.9+hostbuild'));
            @unlink($store->runDir . '/api-install-error.json');
        }
        Store::atomic($store->runDir . '/api-install-error.json', [
            'error' => 'Registration failed',
        ]);
        eq(
            $integration->health()['message'],
            'API setup failed: Registration failed. See Troubleshooting.',
        );
    },
);
