<?php
use DeadlockGuard\ApiRequests;

test(
    'API bridge fixes the request source and rejects a conflicting operation before admission',
    function () {
        [$store, $platform, $old, $a, $b] = scenario('docker', 'docker');
        $store->updateJob($old['id'], ['status' => 'failed']);
        $launched = [];
        $handoffs = readyHandoffs($store, $platform, $launched);
        $inventory = fn() => [
            'workloads' => [
                array_merge($a, $platform->inspect($a)),
                array_merge($b, $platform->inspect($b)),
            ],
            'errors' => [],
        ];
        $bridge = new ApiRequests($store, $handoffs, $inventory);
        $native = ['type' => 'docker', 'id' => $b['id'], 'action' => 'start'];
        $batch = [$native, ['type' => 'docker', 'id' => $a['id'], 'action' => 'start']];
        raises(
            fn() => $bridge->handle([
                'op' => 'route',
                'native' => $native,
                'batch' => $batch,
                'key' => 'api-conflict',
                'allowedTypes' => ['docker'],
            ]),
            'Choose one',
        );
        eq($launched, []);
        eq(count($store->jobs()), 1);
        $result = $bridge->handle([
            'op' => 'route',
            'source' => 'webui',
            'native' => $native,
            'batch' => [$native],
            'key' => 'api-valid',
            'allowedTypes' => ['docker'],
        ]);
        eq($result['job']['source'], 'api');
        eq($launched, [$result['job']['id']]);
        eq(
            $bridge->handle(['op' => 'status', 'id' => $result['job']['id']])['job']['id'],
            $result['job']['id'],
        );
        raises(fn() => $bridge->handle(['op' => 'config']), 'operation');
    },
);

test('API bridge leaves unchecked Docker starts native and does not create a job', function () {
    [$store, $platform, $old, $a, $b] = scenario('docker', 'docker', ['api' => false]);
    $store->updateJob($old['id'], ['status' => 'failed']);
    $launched = [];
    $handoffs = readyHandoffs($store, $platform, $launched);
    $bridge = new ApiRequests(
        $store,
        $handoffs,
        fn() => [
            'workloads' => [
                array_merge($a, $platform->inspect($a)),
                array_merge($b, $platform->inspect($b)),
            ],
            'errors' => [],
        ],
    );
    $native = ['type' => 'docker', 'id' => $b['id'], 'action' => 'start'];
    $result = $bridge->handle([
        'op' => 'route',
        'native' => $native,
        'batch' => [$native],
        'key' => 'api-native',
        'allowedTypes' => ['docker'],
    ]);
    eq($result['managed'], false);
    eq($launched, []);
    eq(count($store->jobs()), 1);
});

test('job admission revalidates API batch conflicts under the registry lock', function () {
    [$jobs, $store, $a, $b] = fixtureJobs();
    raises(
        fn() => $jobs->submit(
            [['workload' => $a, 'action' => 'start']],
            'atomic-batch',
            'api',
            ['docker'],
            [['workload' => $a, 'action' => 'start'], ['workload' => $b, 'action' => 'start']],
        ),
        'Choose one',
    );
    eq($store->jobs(), []);
});
