<?php
use DeadlockGuard\Coordinator;
use DeadlockGuard\Config;
use DeadlockGuard\Store;
use DeadlockGuard\Jobs;
test('all handoff directions wait for shutdown before start', function () {
    foreach (
        [['vm', 'docker'], ['docker', 'vm'], ['vm', 'vm'], ['docker', 'docker']]
        as [$from, $to]
    ) {
        [$store, $platform, $job, $conflict, $target] = scenario($from, $to);
        $platform->delays[Config::key($conflict)] = 0.5;
        (new Coordinator($store, $platform))->run($job['id']);
        eq($store->job($job['id'])['status'], 'succeeded');
        eq($platform->log, ['stop:' . Config::key($conflict), 'start:' . Config::key($target)]);
        ok($platform->time >= 0.5);
    }
});

test('graceful timeout never starts target or forces without opt-in', function () {
    [$store, $platform, $job, $conflict, $target] = scenario('docker', 'vm');
    $platform->refuse[Config::key($conflict)] = true;
    (new Coordinator($store, $platform))->run($job['id']);
    eq($store->job($job['id'])['status'], 'failed');
    eq($platform->log, ['stop:' . Config::key($conflict)]);
    eq($platform->inspect($target)['status'], 'stopped');
});

test('opted-in force waits and starts only if force actually stops workload', function () {
    foreach ([true, false] as $works) {
        [$store, $platform, $job, $conflict, $target] = scenario('vm', 'docker', [
            'forceVm' => true,
        ]);
        $platform->refuse[Config::key($conflict)] = true;
        $platform->forceWorks = $works;
        (new Coordinator($store, $platform))->run($job['id']);
        eq($store->job($job['id'])['status'], $works ? 'succeeded' : 'failed');
        eq(in_array('start:' . Config::key($target), $platform->log), $works);
    }
});

test('VM shutoff without release completion cannot start target', function () {
    [$store, $platform, $job, $conflict, $target] = scenario('vm', 'docker');
    $platform->releaseWorks = false;
    (new Coordinator($store, $platform))->run($job['id']);
    eq($store->job($job['id'])['status'], 'failed');
    eq($platform->inspect($target)['status'], 'stopped');
});

test('missing members prevent any stop and failed startup does not roll back', function () {
    [$store, $platform, $job, $conflict, $target] = scenario('docker', 'vm');
    unset($platform->states[Config::key($conflict)]);
    (new Coordinator($store, $platform))->run($job['id']);
    eq($platform->log, []);
    [$store, $platform, $job, $conflict, $target] = scenario('docker', 'vm');
    $platform->failStart = true;
    (new Coordinator($store, $platform))->run($job['id']);
    eq($store->job($job['id'])['status'], 'failed');
    eq($platform->inspect($conflict)['status'], 'stopped');
});

test('paused and unknown conflicts are never mistaken for stopped', function () {
    foreach (['paused', 'suspended', 'restarting', 'unknown'] as $state) {
        [$store, $platform, $job, $conflict, $target] = scenario('docker', 'vm');
        $platform->states[Config::key($conflict)]['status'] = $state;
        $platform->refuse[Config::key($conflict)] = true;
        (new Coordinator($store, $platform))->run($job['id']);
        eq($platform->inspect($target)['status'], 'stopped');
    }
});

test(
    'container restart uses graceful stop and start; VM restart remains guest reboot',
    function () {
        foreach (['docker', 'vm'] as $type) {
            [$store, $platform, $job, $conflict, $target] = scenario($type, $type);
            $store->updateJob($job['id'], ['status' => 'failed']);
            $platform->states[Config::key($conflict)]['status'] = 'stopped';
            $platform->states[Config::key($target)]['status'] = 'running';
            $job = (new Jobs($store))->submit(
                [['workload' => $target, 'action' => 'restart']],
                'restart-1',
            );
            (new Coordinator($store, $platform))->run($job['id']);
            eq($store->job($job['id'])['status'], 'succeeded');
            eq(
                $platform->log,
                $type === 'vm'
                    ? ['restart:' . Config::key($target)]
                    : ['stop:' . Config::key($target), 'start:' . Config::key($target)],
            );
        }
    },
);

test('overlapping groups stop every conflict and use conservative force policy', function () {
    $conflict = member('docker', 'a');
    $target = member('docker', 'b');
    $other = member('docker', 'c');
    $directory = tempdir();
    $store = new Store($directory . '/run', $directory . '/config');
    $cfg = config([
        group('one', [$conflict, $target], ['containerTimeout' => 1, 'forceContainer' => true]),
        group('two', [$target, $other], ['containerTimeout' => 2]),
    ]);
    $store->saveConfig($cfg);
    $platform = new SimulatedPlatform([$conflict, $target, $other]);
    $platform->states['docker:a']['status'] = 'running';
    $platform->states['docker:c']['status'] = 'running';
    $job = (new Jobs($store))->submit([['workload' => $target, 'action' => 'start']], 'overlap-1');
    (new Coordinator($store, $platform))->run($job['id']);
    eq($store->job($job['id'])['status'], 'succeeded');
    eq($platform->log, ['stop:docker:a', 'stop:docker:c', 'start:docker:b']);
});

test('waiting progress names pending conflicts by type and clears after completion', function () {
    $conflict = member('vm', '11111111-1111-1111-1111-111111111111');
    $target = member('docker', 'docker-container-xyz');
    $other = member('docker', 'target');
    $directory = member('docker', 'already-stopped');
    $dir = tempdir();
    $store = new Store($dir . '/run', $dir . '/config');
    $store->saveConfig(config([group('shared', [$conflict, $target, $other, $directory])]));
    $platform = new class ([$conflict, $target, $other, $directory], $store) extends
        SimulatedPlatform
    {
        public array $observed = [];
        public function __construct(array $members, private Store $store)
        {
            parent::__construct($members);
        }
        public function pause(): void
        {
            $this->observed[] = Jobs::publicJob($this->store->jobs()[0]);
            parent::pause();
        }
    };
    $platform->states[Config::key($conflict)]['name'] = 'lava-lamp';
    $platform->states[Config::key($conflict)]['status'] = 'running';
    $platform->states[Config::key($target)]['status'] = 'running';
    $job = (new Jobs($store))->submit([['workload' => $other, 'action' => 'start']], 'feedback-1');
    (new Coordinator($store, $platform))->run($job['id']);
    eq(array_column($platform->observed[0]['progress']['workloads'] ?? [], 'name', 'type'), [
        'docker' => 'docker-container-xyz',
        'vm' => 'lava-lamp',
    ]);
    eq(array_column(end($platform->observed)['progress']['workloads'] ?? [], 'name', 'type'), [
        'vm' => 'lava-lamp',
    ]);
    eq($store->job($job['id'])['status'], 'succeeded');
    eq(Jobs::publicJob($store->job($job['id']))['progress']['workloads'], []);
});
