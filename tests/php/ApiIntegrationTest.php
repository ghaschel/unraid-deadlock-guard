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
