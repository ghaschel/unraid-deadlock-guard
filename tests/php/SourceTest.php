<?php
use DeadlockGuard\Config;
use DeadlockGuard\Router;
use DeadlockGuard\Jobs;
use DeadlockGuard\Store;
use DeadlockGuard\Coordinator;
use DeadlockGuard\Gate;

test('source selection filters overlapping groups and bulk conflicts', function () {
    $a = member('docker', 'a');
    $b = member('docker', 'b');
    $c = member('docker', 'c');
    $config = config([
        group('browser', [$a, $b], ['webui' => true, 'api' => false]),
        group('phone', [$b, $c], ['webui' => false, 'api' => true]),
    ]);
    $request = [['workload' => $b, 'action' => 'start']];
    eq(Config::plan($config, $request, 'webui')['conflicts'], [$a]);
    eq(Config::plan($config, $request, 'api')['conflicts'], [$c]);
    raises(fn() => Config::plan($config, $request, 'unknown'), 'source');
    $batch = [['workload' => $a, 'action' => 'start'], ['workload' => $b, 'action' => 'start']];
    raises(fn() => Config::plan($config, $batch, 'webui'), 'Choose one');
    eq(Config::plan($config, $batch, 'api')['groups'], ['phone']);
});

test('unchecked sources bypass Docker but retain a VM start authorization path', function () {
    $vm = member('vm', '11111111-1111-1111-1111-111111111111');
    $docker = member('docker', 'a');
    $config = config([group('gpu', [$vm, $docker], ['api' => false])]);
    $inventory = [
        'workloads' => [
            array_merge($vm, ['status' => 'stopped']),
            array_merge($docker, ['status' => 'stopped']),
        ],
        'errors' => [],
    ];
    $router = new Router($config, $inventory);
    ok(!$router->route(['type' => 'docker', 'id' => 'a', 'action' => 'start'], 'api')['managed']);
    ok($router->route(['type' => 'vm', 'id' => $vm['id'], 'action' => 'start'], 'api')['managed']);
    ok(
        $router->route(['type' => 'vm', 'id' => $vm['id'], 'action' => 'restart'], 'api')[
            'managed'
        ],
    );
    ok($router->route(['type' => 'docker', 'id' => 'a', 'action' => 'start'], 'webui')['managed']);
});

test('API VM start and resume preserve the native running-state operation', function () {
    $vm = member('vm', '11111111-1111-1111-1111-111111111111');
    $config = config([group('gpu', [$vm, member('docker', 'a')])]);
    foreach (
        ['paused' => 'resume', 'stopped' => 'start', 'running' => 'start']
        as $state => $expected
    ) {
        $router = new Router($config, [
            'workloads' => [array_merge($vm, ['status' => $state])],
            'errors' => [],
        ]);
        foreach (['start', 'resume'] as $action) {
            eq(
                $router->route(['type' => 'vm', 'id' => $vm['id'], 'action' => $action], 'api')[
                    'requests'
                ][0]['action'],
                $expected,
            );
        }
    }
});

test(
    'unchecked VM source starts without stopping other members and consumes a single use permit',
    function () {
        $vm = member('vm', '11111111-1111-1111-1111-111111111111');
        $docker = member('docker', 'a');
        $dir = tempdir();
        $store = new Store($dir . '/run', $dir . '/config');
        $store->saveConfig(config([group('gpu', [$vm, $docker], ['api' => false])]));
        $platform = new class ([$vm, $docker], $store) extends SimulatedPlatform {
            public function __construct(array $members, private Store $store)
            {
                parent::__construct($members);
            }
            public function act(array $member, string $action): void
            {
                (new Gate($this->store))->prepare($member);
                $this->log[] = $action . ':' . Config::key($member);
                $this->states[Config::key($member)]['status'] = 'running';
            }
        };
        $platform->states['docker:a']['status'] = 'running';
        $job = (new Jobs($store))->submit(
            [['workload' => $vm, 'action' => 'start']],
            'api-bypass-vm',
            'api',
        );
        eq($job['plan']['groups'], []);
        eq($job['plan']['conflicts'], []);
        (new Coordinator($store, $platform))->run($job['id']);
        eq($store->job($job['id'])['status'], 'succeeded');
        eq($platform->log, ['start:vm:' . $vm['id']]);
        eq($store->job($job['id'])['handoff'], false);
        eq($store->job($job['id'])['history'], []);
        eq($platform->states['docker:a']['status'], 'running');
        raises(fn() => (new Gate($store))->prepare($vm), 'Start this VM');
    },
);

test('API permissions include every affected resource type before job admission', function () {
    $vm = member('vm', '11111111-1111-1111-1111-111111111111');
    $docker = member('docker', 'a');
    $dir = tempdir();
    $store = new Store($dir . '/run', $dir . '/config');
    $store->saveConfig(config([group('gpu', [$vm, $docker])]));
    $jobs = new Jobs($store);
    $requests = [['workload' => $docker, 'action' => 'start']];
    raises(fn() => $jobs->submit($requests, 'restricted-api', 'api', ['docker']), 'permission');
    eq($store->jobs(), []);
    $job = $jobs->submit($requests, 'authorized-api', 'api', ['docker', 'vm']);
    eq($job['source'] ?? null, 'api');
    eq($jobs->submit($requests, 'duplicate-api', 'api', ['docker', 'vm'])['id'], $job['id']);
    raises(fn() => $jobs->submit($requests, 'other-source', 'webui'), 'busy');
    raises(fn() => $jobs->submit($requests, 'authorized-api', 'webui'), 'different');
});

test(
    'unchecked API VM reboot uses guest reboot without handing off or entering native shutdown/create',
    function () {
        $vm = member('vm', '11111111-1111-1111-1111-111111111111');
        $container = member('docker', 'a');
        $config = config([group('gpu', [$vm, $container], ['api' => false])]);
        $router = new Router($config, [
            'workloads' => [array_merge($vm, ['status' => 'running'])],
            'errors' => [],
        ]);
        $route = $router->route(['type' => 'vm', 'id' => $vm['id'], 'action' => 'restart'], 'api');
        ok($route['managed']);
        eq(Config::plan($config, $route['requests'], 'api')['conflicts'], []);
        $dir = tempdir();
        $store = new Store($dir . '/run', $dir . '/config');
        $store->saveConfig($config);
        $platform = new SimulatedPlatform([$vm, $container]);
        $platform->states[Config::key($vm)]['status'] = 'running';
        $job = (new Jobs($store))->submit($route['requests'], 'api-guest-reboot', 'api');
        (new Coordinator($store, $platform))->run($job['id']);
        eq($store->job($job['id'])['status'], 'succeeded');
        eq($platform->log, ['restart:' . Config::key($vm)]);
    },
);

test(
    'API reset rejects grouped VMs before native destroy and leaves ungrouped resets native',
    function () {
        $vm = member('vm', '11111111-1111-1111-1111-111111111111');
        $inventory = ['workloads' => [array_merge($vm, ['status' => 'running'])], 'errors' => []];
        $config = config([group('gpu', [$vm, member('docker', 'a')], ['api' => false])]);
        $action = ['type' => 'vm', 'id' => $vm['id'], 'action' => 'reset'];
        raises(
            fn() => (new Router($config, $inventory))->route($action, 'api'),
            'Reset is not supported',
        );
        eq((new Router(config([]), $inventory))->route($action, 'api'), [
            'managed' => false,
            'requests' => [],
        ]);
        raises(
            fn() => (new Router(config([]), $inventory))->route($action, 'webui'),
            'Invalid native action',
        );
    },
);
