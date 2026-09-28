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
        eq($store->job($job['id'])['handoff'] ?? null, true);
        eq($platform->log, ['stop:' . Config::key($conflict), 'start:' . Config::key($target)]);
        ok($platform->time >= 0.5);
    }
});

test('VM graceful timeout never starts target or forces without opt-in', function () {
    [$store, $platform, $job, $conflict, $target] = scenario('vm', 'docker');
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
        $platform->forceWorks = false;
        (new Coordinator($store, $platform))->run($job['id']);
        eq($platform->inspect($target)['status'], 'stopped');
    }
});

test('container restart uses native stop and start; VM restart remains guest reboot', function () {
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
});

test('overlapping groups stop every conflict', function () {
    $conflict = member('docker', 'a');
    $target = member('docker', 'b');
    $other = member('docker', 'c');
    $directory = tempdir();
    $store = new Store($directory . '/run', $directory . '/config');
    $cfg = config([
        group('one', [$conflict, $target], ['containerTimeout' => 1]),
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

test('container stop escalates after the group timeout without a force checkbox', function () {
    foreach (['vm', 'docker'] as $targetType) {
        [$store, $platform, $job, $conflict, $target] = scenario('docker', $targetType, [
            'containerTimeout' => 2,
        ]);
        $platform->refuse[Config::key($conflict)] = true;
        (new Coordinator($store, $platform))->run($job['id']);
        eq($store->job($job['id'])['status'], 'succeeded');
        eq($platform->inspect($conflict)['status'], 'stopped');
        eq($platform->inspect($target)['status'], 'running');
        eq($platform->time, 2.0);
        eq($platform->log, ['stop:' . Config::key($conflict), 'start:' . Config::key($target)]);
    }
});

test('container stop uses the longest timeout from overlapping groups', function () {
    [$store, $platform, $job, $conflict, $target] = scenario('docker', 'vm');
    $configuration = $job['config'];
    $configuration['groups'][] = config([
        group('second', [$conflict, $target], ['containerTimeout' => 3]),
    ])['groups'][0];
    $store->updateJob($job['id'], [
        'config' => $configuration,
        'plan' => Config::plan($configuration, $job['plan']['requests']),
    ]);
    $platform->refuse[Config::key($conflict)] = true;
    (new Coordinator($store, $platform))->run($job['id']);
    eq($store->job($job['id'])['status'], 'succeeded');
    eq($platform->time, 3.0);
});

test(
    'a successful Docker stop response cannot start the target while the container remains active',
    function () {
        [$store, $platform, $job, $conflict, $target] = scenario('docker', 'vm');
        $platform->refuse[Config::key($conflict)] = true;
        $platform->forceWorks = false;
        (new Coordinator($store, $platform))->run($job['id']);
        $result = $store->job($job['id']);
        eq($result['status'], 'failed');
        eq($result['states'][Config::key($conflict)]['status'], 'running');
        eq($platform->inspect($target)['status'], 'stopped');
        eq($platform->log, ['stop:' . Config::key($conflict)]);
    },
);

test('starts with no active conflicts stay authorized but produce no handoff history', function () {
    foreach (
        [['vm', 'docker'], ['docker', 'vm'], ['vm', 'vm'], ['docker', 'docker']]
        as [$from, $to]
    ) {
        [$store, $platform, $job, $conflict, $target] = scenario($from, $to);
        $platform->states[Config::key($conflict)]['status'] = 'stopped';
        $jobs = new Jobs($store);
        eq($jobs->submit($job['plan']['requests'], 'quiet-duplicate')['id'], $job['id']);
        raises(
            fn() => $jobs->submit(
                [['workload' => $conflict, 'action' => 'start']],
                'quiet-opposing',
            ),
            'busy',
        );
        (new Coordinator($store, $platform))->run($job['id']);
        $result = $store->job($job['id']);
        eq($result['status'], 'succeeded');
        eq(Jobs::publicJob($result)['handoff'] ?? null, false);
        eq($result['history'], []);
        eq($jobs->activity(), ['jobs' => [], 'actionErrors' => []]);
        eq($platform->log, ['start:' . Config::key($target)]);
    }
});

test('restarting a container alone is not a handoff', function () {
    [$store, $platform, $old, $conflict, $target] = scenario('docker', 'docker');
    $store->updateJob($old['id'], ['status' => 'failed']);
    $platform->states[Config::key($conflict)]['status'] = 'stopped';
    $platform->states[Config::key($target)]['status'] = 'running';
    $job = (new Jobs($store))->submit(
        [['workload' => $target, 'action' => 'restart']],
        'quiet-restart',
    );
    (new Coordinator($store, $platform))->run($job['id']);
    $result = $store->job($job['id']);
    eq($result['status'], 'succeeded');
    eq($result['handoff'] ?? null, false);
    eq($result['history'], []);
    eq($platform->log, ['stop:' . Config::key($target), 'start:' . Config::key($target)]);
});

test('failed ordinary starts remain diagnostic errors rather than handoff entries', function () {
    [$store, $platform, $job, $conflict, $target] = scenario('docker', 'vm');
    $platform->states[Config::key($conflict)]['status'] = 'stopped';
    $platform->failStart = true;
    (new Coordinator($store, $platform))->run($job['id']);
    $result = $store->job($job['id']);
    eq($result['status'], 'failed');
    eq($result['handoff'] ?? null, false);
    ok(!str_contains($result['phase'], 'Handoff'));
    $activity = (new Jobs($store))->activity();
    eq($activity['jobs'], []);
    eq(array_column($activity['actionErrors'], 'id'), [$job['id']]);
});

test(
    'uncertain ordinary starts retain reservations and remain visible in troubleshooting',
    function () {
        [$store, $original, $job, $conflict, $target] = scenario('docker', 'vm');
        $platform = new class ([$conflict, $target]) extends SimulatedPlatform {
            public function act(array $member, string $action): void
            {
                throw new DeadlockGuard\UncertainOperation('Start response lost');
            }
        };
        (new Coordinator($store, $platform))->run($job['id']);
        $result = $store->job($job['id']);
        eq($result['status'], 'quarantined');
        eq($result['handoff'], false);
        eq($result['inFlight']['action'], 'start');
        eq($result['history'], []);
        $jobs = new Jobs($store);
        eq($jobs->activity()['jobs'], []);
        eq(array_column($jobs->activity()['actionErrors'], 'id'), [$job['id']]);
        raises(
            fn() => $jobs->submit(
                [['workload' => $conflict, 'action' => 'start']],
                'uncertain-opposing',
            ),
            'busy',
        );
    },
);

test('existing jobs without a handoff flag remain in recent handoffs', function () {
    [$store, $platform, $job] = scenario('docker', 'vm');
    unset($job['handoff']);
    $job['status'] = 'succeeded';
    $store->putJob($job);
    $activity = (new Jobs($store))->activity();
    eq(array_column($activity['jobs'], 'id'), [$job['id']]);
    eq($activity['jobs'][0]['handoff'], true);
    eq($activity['actionErrors'], []);
});
