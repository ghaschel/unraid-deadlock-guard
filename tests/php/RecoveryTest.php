<?php
use DeadlockGuard\Recovery;
use DeadlockGuard\Store;
use DeadlockGuard\Jobs;
use DeadlockGuard\ProcessIdentity;
test('orphaned idle worker reconciles, uncertain operation never expires', function () {
    [$store, $platform, $job, $a, $b] = scenario('docker', 'vm');
    $store->updateJob($job['id'], [
        'status' => 'running',
        'pid' => 99999999,
        'processIdentity' => 'gone',
    ]);
    (new Recovery($store, $platform))->reconcile();
    eq($store->job($job['id'])['status'], 'failed');
    $job = (new Jobs($store))->submit([['workload' => $b, 'action' => 'start']], 'recovery-2');
    $store->updateJob($job['id'], [
        'status' => 'running',
        'pid' => 99999999,
        'processIdentity' => 'gone',
        'inFlight' => ['action' => 'start', 'at' => 1],
    ]);
    (new Recovery($store, $platform))->reconcile();
    eq($store->job($job['id'])['status'], 'quarantined');
    raises(
        fn() => (new Jobs($store))->submit([['workload' => $a, 'action' => 'start']], 'recovery-3'),
        'busy',
    );
});

test('reconciliation never steals a live worker and draining prevents admission', function () {
    [$store, $platform, $job, $a, $b] = scenario('docker', 'vm');
    $store->updateJob($job['id'], [
        'status' => 'running',
        'pid' => getmypid(),
        'processIdentity' => ProcessIdentity::of(getmypid()),
    ]);
    (new Recovery($store, $platform))->reconcile();
    eq($store->job($job['id'])['status'], 'running');
    Store::atomic($store->runDir . '/draining.json', ['at' => microtime(true)]);
    raises(
        fn() => (new Jobs($store))->submit([['workload' => $a, 'action' => 'start']], 'draining-1'),
        'draining',
    );
});
