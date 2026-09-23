<?php
use DeadlockGuard\Store;
use DeadlockGuard\Jobs;
function fixtureJobs(): array
{
    $directory = tempdir();
    $store = new Store($directory . '/run', $directory . '/config.json');
    $a = member('docker', 'a');
    $b = member('docker', 'b');
    $store->saveConfig(config([group('gpu', [$a, $b])]));
    return [new Jobs($store), $store, $a, $b];
}
test('job admission is idempotent and reserves conflicting groups', function () {
    [$jobs, $store, $a, $b] = fixtureJobs();
    $one = $jobs->submit([['workload' => $a, 'action' => 'start']], 'request-1');
    eq($jobs->submit([['workload' => $a, 'action' => 'start']], 'request-1')['id'], $one['id']);
    eq($jobs->submit([['workload' => $a, 'action' => 'start']], 'request-2')['id'], $one['id']);
    raises(fn() => $jobs->submit([['workload' => $b, 'action' => 'start']], 'request-3'), 'busy');
    raises(fn() => $store->saveConfig(config([])), 'active');
    raises(
        fn() => $jobs->submit([['workload' => $b, 'action' => 'start']], 'request-1'),
        'different',
    );
});

test('reservations survive worker death and time passage', function () {
    [$jobs, $store, $a, $b] = fixtureJobs();
    $one = $jobs->submit([['workload' => $a, 'action' => 'start']], 'old-request');
    $store->updateJob($one['id'], [
        'status' => 'quarantined',
        'updatedAt' => 1,
        'error' => 'Lost worker',
    ]);
    raises(
        fn() => (new Jobs(new Store($store->runDir, $store->configFile)))->submit(
            [['workload' => $b, 'action' => 'start']],
            'new-request',
        ),
        'busy',
    );
});

test('malformed configuration never silently becomes an empty config', function () {
    $directory = tempdir();
    file_put_contents($directory . '/config.json', '{broken');
    $store = new Store($directory . '/run', $directory . '/config.json');
    raises(fn() => $store->config(), 'configuration');
});

test('invalid batch creates no jobs and no reservations', function () {
    [$jobs, $store, $a, $b] = fixtureJobs();
    raises(
        fn() => $jobs->submit(
            [['workload' => $a, 'action' => 'start'], ['workload' => $b, 'action' => 'start']],
            'batch-001',
        ),
        'exclusive group',
    );
    eq($store->jobs(), []);
});
