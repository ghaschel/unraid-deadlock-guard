<?php
use DeadlockGuard\Gate;
use DeadlockGuard\Store;
use DeadlockGuard\ProcessIdentity;
test('hook admits a managed VM exactly once for a live running job', function () {
    [$store, $platform, $job, $a, $b] = scenario('docker', 'vm');
    $store->updateJob($job['id'], [
        'status' => 'running',
        'pid' => getmypid(),
        'processIdentity' => ProcessIdentity::of(getmypid()),
    ]);
    $gate = new Gate($store);
    raises(fn() => $gate->prepare($b), 'Start this VM');
    $gate->issue($b, $job['id']);
    $gate->prepare($b);
    raises(fn() => $gate->prepare($b), 'Start this VM');
});

test('expired or orphaned VM permissions cannot be consumed', function () {
    [$store, $platform, $job, $a, $b] = scenario('docker', 'vm');
    $store->updateJob($job['id'], [
        'status' => 'running',
        'pid' => getmypid(),
        'processIdentity' => ProcessIdentity::of(getmypid()),
    ]);
    $gate = new Gate($store);
    $gate->issue($b, $job['id']);
    $file = $store->permissionPath($b);
    $permit = Store::read($file);
    $permit['expiresAt'] = 1;
    Store::atomic($file, $permit);
    raises(fn() => $gate->prepare($b), 'authorization');
    $gate->issue($b, $job['id']);
    $store->updateJob($job['id'], ['status' => 'quarantined']);
    raises(fn() => $gate->prepare($b), 'authorization');
});

test('unmanaged VM hook permits starts and lifecycle events require no platform', function () {
    [$store, $platform, $job, $a, $b] = scenario('docker', 'vm');
    $g = new Gate($store);
    $other = member('vm', '33333333-3333-3333-3333-333333333333');
    $g->prepare($other);
    $g->event($b, 'release');
    $event = Store::read($store->eventPath($b));
    eq($event['phase'], 'release');
    ok($event['release'] > 0);
});
