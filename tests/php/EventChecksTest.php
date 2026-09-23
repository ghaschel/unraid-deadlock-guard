<?php
use DeadlockGuard\Store;
use DeadlockGuard\Jobs;
use DeadlockGuard\PendingJobs;
use DeadlockGuard\WorkerSupervisor;
use DeadlockGuard\ProcessIdentity;
use DeadlockGuard\Lifecycle;
use DeadlockGuard\Config;
test(
    'worker supervisor recovers crashes without a timer and retains uncertain reservations',
    function () {
        foreach ([false, true] as $inFlight) {
            [$store, $platform, $job, $conflict, $target] = scenario('docker', 'vm');
            $script = workerFixture($store, 'crash', $inFlight);
            (new WorkerSupervisor($store, $platform, $script))->run($job['id']);
            eq($store->job($job['id'])['status'], $inFlight ? 'quarantined' : 'failed');
            eq($platform->log, []);
            if ($inFlight) {
                raises(
                    fn() => (new Jobs($store))->submit(
                        [['workload' => $conflict, 'action' => 'start']],
                        'opposing-crash',
                    ),
                    'busy',
                );
            }
        }
    },
);

test(
    'worker startup failure becomes visible and completed result survives supervisor exit',
    function () {
        foreach (
            ['startup-failure' => 'failed', 'complete' => 'succeeded']
            as $scenario => $status
        ) {
            [$store, $platform, $job] = scenario('docker', 'docker');
            (new WorkerSupervisor($store, $platform, workerFixture($store, $scenario)))->run(
                $job['id'],
            );
            eq($store->job($job['id'])['status'], $status);
        }
    },
);

test('duplicate supervisors do not spawn a second child', function () {
    [$store, $platform, $job] = scenario('docker', 'docker');
    $store->updateJob($job['id'], [
        'supervisor' => ['pid' => getmypid(), 'processIdentity' => ProcessIdentity::of(getmypid())],
    ]);
    $flag = dirname($store->runDir) . '/launched';
    (new WorkerSupervisor($store, $platform, workerFixture($store, 'mark-launched')))->run(
        $job['id'],
    );
    ok(!is_file($flag));
    eq($store->job($job['id'])['status'], 'queued');
});

test(
    'pending job checks retry queues without integration changes and skip drained queues',
    function () {
        [$store, $platform, $job] = scenario('docker', 'docker');
        $launched = [];
        $pending = new PendingJobs($store, $platform, function ($id) use (&$launched) {
            $launched[] = $id;
        });
        $pending->check(launchQueued: false);
        eq($launched, []);
        $pending->check();
        eq($launched, [$job['id']]);
        Store::atomic($store->runDir . '/draining.json', ['reason' => 'array']);
        $pending->check();
        eq($launched, [$job['id']]);
        ok(
            !is_file(dirname($store->configFile) . '/installed.json'),
            'Job review installed VM integration',
        );
    },
);

test(
    'handoff admission reviews dead workers before reserving groups and checks required members',
    function () {
        [$store, $platform, $old, $conflict, $target] = scenario('docker', 'docker');
        $launched = [];
        $handoffs = readyHandoffs($store, $platform, $launched);
        $store->updateJob($old['id'], [
            'status' => 'running',
            'pid' => 99999999,
            'processIdentity' => 'gone',
        ]);
        $next = $handoffs->submit([['workload' => $conflict, 'action' => 'start']], 'after-crash');
        eq($store->job($old['id'])['status'], 'failed');
        eq($launched, [$next['id']]);
        eq($platform->log, []);
        $store->updateJob($next['id'], ['status' => 'failed']);
        unset($platform->states[Config::key($target)]);
        raises(
            fn() => $handoffs->submit(
                [['workload' => $conflict, 'action' => 'start']],
                'missing-member',
            ),
            'Missing',
        );
        eq(count($store->jobs()), 2);
    },
);

test('invalid batches do not recover jobs or launch queued handoffs', function () {
    [$store, $platform, $job, $conflict, $target] = scenario('docker', 'docker');
    $launched = [];
    $handoffs = readyHandoffs($store, $platform, $launched);
    $store->updateJob($job['id'], [
        'status' => 'running',
        'pid' => 99999999,
        'processIdentity' => 'gone',
    ]);
    raises(
        fn() => $handoffs->submit(
            [
                ['workload' => $conflict, 'action' => 'start'],
                ['workload' => $target, 'action' => 'start'],
            ],
            'invalid-batch',
        ),
        'group',
    );
    eq($store->job($job['id'])['status'], 'running');
    eq($launched, []);
    eq($platform->log, []);
});

test('integration checks leave queued and abandoned jobs untouched', function () {
    [$store, $platform, $job] = scenario('docker', 'docker');
    $directory = dirname($store->runDir);
    Store::atomic(dirname($store->configFile) . '/installed.json', ['installed' => true]);
    // Seed matching hook first because active jobs intentionally prevent replacing it.
    mkdir($directory . '/etc/libvirt/hooks/qemu.d', 0755, true);
    copy(
        dirname(__DIR__, 2) . '/src/usr/local/emhttp/plugins/deadlock-guard/scripts/qemu-hook',
        $directory . '/etc/libvirt/hooks/qemu.d/99-deadlock-guard',
    );
    chmod($directory . '/etc/libvirt/hooks/qemu.d/99-deadlock-guard', 0755);
    (new Lifecycle($store, $directory, fn() => null))->check();
    eq($store->job($job['id'])['status'], 'queued');
    $store->updateJob($job['id'], [
        'status' => 'running',
        'pid' => 99999999,
        'processIdentity' => 'gone',
    ]);
    (new Lifecycle($store, $directory, fn() => null))->check();
    eq($store->job($job['id'])['status'], 'running');
});

test('duplicate accepted requests still join when members become unavailable', function () {
    [$store, $platform, $job, $conflict, $target] = scenario('docker', 'docker');
    $launched = [];
    $handoffs = readyHandoffs($store, $platform, $launched);
    $store->updateJob($job['id'], [
        'status' => 'running',
        'pid' => getmypid(),
        'processIdentity' => ProcessIdentity::of(getmypid()),
    ]);
    unset($platform->states[Config::key($conflict)]);
    eq(
        $handoffs->submit([['workload' => $target, 'action' => 'start']], 'second-browser')['id'],
        $job['id'],
    );
    $store->updateJob($job['id'], ['status' => 'failed']);
    eq(
        $handoffs->submit([['workload' => $target, 'action' => 'start']], 'scenario-1')['id'],
        $job['id'],
    );
    eq($launched, []);
    raises(
        fn() => $handoffs->submit([['workload' => $conflict, 'action' => 'start']], 'scenario-1'),
        'different request',
    );
});

test('Docker-only admission does not repair or depend on the VM hook', function () {
    [$store, $platform, $old, $conflict, $target] = scenario('docker', 'docker');
    $store->updateJob($old['id'], ['status' => 'failed']);
    $launched = [];
    $handoffs = readyHandoffs($store, $platform, $launched);
    $hook = dirname($store->runDir) . '/etc/libvirt/hooks/qemu.d/99-deadlock-guard';
    mkdir(dirname($hook), 0755, true);
    file_put_contents($hook, 'foreign hook');
    $job = $handoffs->submit([['workload' => $conflict, 'action' => 'start']], 'docker-only');
    eq($launched, [$job['id']]);
    eq(file_get_contents($hook), 'foreign hook');
});
