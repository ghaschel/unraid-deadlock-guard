<?php
use DeadlockGuard\Store;
use DeadlockGuard\Jobs;
use DeadlockGuard\Coordinator;
use DeadlockGuard\Config;
use DeadlockGuard\UncertainOperation;
test(
    'opposing processes cannot acquire the same groups; duplicates join across processes',
    function () {
        foreach ([false, true] as $duplicate) {
            $directory = tempdir();
            $store = new Store($directory . '/run', $directory . '/config');
            $a = member('docker', 'a');
            $b = member('docker', 'b');
            $store->saveConfig(config([group('g', [$a, $b])]));
            $script = __DIR__ . '/fixtures/submit-job.php';
            $processes = [];
            $pipes = [];
            foreach (['a', $duplicate ? 'a' : 'b'] as $index => $id) {
                $processes[$index] = proc_open(
                    [PHP_BINARY, $script, $directory, $id, 'process-' . $index],
                    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                    $pipes[$index],
                );
            }
            touch($directory . '/go');
            $results = [];
            foreach ($processes as $index => $process) {
                fclose($pipes[$index][0]);
                $results[] = stream_get_contents($pipes[$index][1]);
                fclose($pipes[$index][1]);
                fclose($pipes[$index][2]);
                proc_close($process);
            }
            eq(count($store->active()), 1);
            if ($duplicate) {
                eq($results[0], $results[1]);
            } else {
                eq(count(array_filter($results, fn($x) => str_contains($x, 'busy'))), 1);
            }
        }
    },
);

test('uncertain platform operation keeps reservation and records actual target state', function () {
    [$store, $base, $job, $a, $b] = scenario('docker', 'vm');
    $platform = new class ([$a, $b]) extends SimulatedPlatform {
        public function act(array $member, string $action): void
        {
            $this->states[Config::key($member)]['status'] = 'running';
            throw new UncertainOperation('connection lost');
        }
    };
    (new Coordinator($store, $platform))->run($job['id']);
    $job = $store->job($job['id']);
    eq($job['status'], 'quarantined');
    eq($job['states'][Config::key($b)]['status'], 'running');
    ok($job['inFlight'] !== null);
});

test('valid VM resume and wake preserve action; config changes refuse active jobs', function () {
    foreach (['resume' => 'paused', 'wake' => 'suspended'] as $action => $state) {
        [$store, $platform, $old, $a, $b] = scenario('docker', 'vm');
        $store->updateJob($old['id'], ['status' => 'failed']);
        $platform->states[Config::key($b)]['status'] = $state;
        $job = (new Jobs($store))->submit(
            [['workload' => $b, 'action' => $action]],
            'action-' . $action,
        );
        raises(fn() => $store->saveConfig(config([])), 'active');
        (new Coordinator($store, $platform))->run($job['id']);
        eq($store->job($job['id'])['status'], 'succeeded');
        eq(end($platform->log), $action . ':' . Config::key($b));
    }
});
