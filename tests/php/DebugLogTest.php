<?php
use DeadlockGuard\DebugLog;
use DeadlockGuard\Store;
use DeadlockGuard\Coordinator;
use DeadlockGuard\Config;

test('debug logging is opt-in, persists its toggle and stops writing when disabled', function () {
    $dir = tempdir();
    $log = new DebugLog($dir . '/run', $dir . '/debug.json');
    eq($log->enabled(), false);
    $log->record('test.disabled', ['jobId' => 'none']);
    ok(!is_file($dir . '/run/debug.log'));
    $log->setEnabled(true);
    $other = new DebugLog($dir . '/run', $dir . '/debug.json');
    eq($other->enabled(), true);
    $other->record('test.enabled', ['jobId' => '123']);
    ok(str_contains($log->download(), 'test.enabled'));
    $log->setEnabled(false);
    $before = $log->download();
    $other->record('test.disabled', []);
    eq($log->download(), $before);
    raises(fn() => $log->setEnabled('true'), 'boolean');
    Store::atomic($dir . '/debug.json', ['version' => 1, 'enabled' => 'true']);
    eq($log->enabled(), false);
});

test('debug logs exclude secrets and arbitrary payloads and stay bounded in RAM', function () {
    $dir = tempdir();
    $log = new DebugLog($dir . '/run', $dir . '/debug.json');
    $log->setEnabled(true);
    $log->record('test.fields', [
        'jobId' => 'visible',
        'state' => 'stopped',
        'token' => 'secret-token',
        'csrf' => 'secret-csrf',
        'password' => 'secret-password',
        'error' => 'secret-error',
        'argv' => ['secret-command'],
        'xml' => '<secret-xml/>',
        'environment' => ['secret-env'],
    ]);
    $text = $log->download();
    ok(str_contains($text, 'visible'));
    ok(!str_contains($text, 'secret-'));
    for ($i = 0; $i < 2200; $i++) {
        $log->record('test.rotate', ['workloadId' => str_repeat('x', 500)]);
    }
    clearstatcache();
    ok(is_file($dir . '/run/debug.log.1'));
    ok(filesize($dir . '/run/debug.log') <= 524288);
    ok(filesize($dir . '/run/debug.log.1') <= 524288);
    eq(fileperms($dir . '/run/debug.log') & 0777, 0600);
    foreach (explode("\n", trim(file_get_contents($dir . '/run/debug.log'))) as $line) {
        ok(is_array(json_decode($line, true, 32, JSON_THROW_ON_ERROR)));
    }
});

test(
    'debug logging cannot delay the VM hook or fail an action when storage is unavailable',
    function () {
        $dir = tempdir();
        $log = new DebugLog($dir . '/run', $dir . '/debug.json');
        $log->setEnabled(true);
        $handle = fopen($dir . '/run/debug.lock', 'c');
        flock($handle, LOCK_EX);
        $before = microtime(true);
        $log->record('hook.busy');
        ok(microtime(true) - $before < 0.5);
        flock($handle, LOCK_UN);
        fclose($handle);
        unlink($dir . '/run/debug.log');
        mkdir($dir . '/run/debug.log');
        $log->record('test.unwritable');
        eq($log->enabled(), true);
    },
);

test('debug traces ordinary starts without recording them as handoffs', function () {
    [$store, $platform, $job, $conflict, $target] = scenario('docker', 'vm');
    $log = new DebugLog($store->runDir, dirname($store->configFile) . '/debug.json');
    $log->setEnabled(true);
    $platform->states[Config::key($conflict)]['status'] = 'stopped';
    (new Coordinator($store, $platform))->run($job['id']);
    $text = $log->download();
    ok(str_contains($text, 'job.phase'));
    ok(str_contains($text, 'command.started'));
    ok(str_contains($text, $job['id']));
    eq($store->job($job['id'])['history'], []);
    eq($store->job($job['id'])['status'], 'succeeded');
});

test('command diagnostics retain timing and exit status without stdin or output', function () {
    $dir = tempdir();
    $log = new DebugLog($dir . '/run', $dir . '/debug.json');
    $log->setEnabled(true);
    $runner = new DeadlockGuard\Runner($log);
    raises(
        fn() => $runner->run(
            [PHP_BINARY, '-r', 'fwrite(STDERR,"secret-output"); exit(2);'],
            input: 'secret-stdin',
        ),
        'secret-output',
    );
    $text = $log->download();
    ok(str_contains($text, 'process.failed'));
    ok(str_contains($text, '"exitCode":2'));
    ok(str_contains($text, 'durationMs'));
    ok(!str_contains($text, 'secret-'));
});

test('debug settings and logs refuse special files and oversized settings', function () {
    $dir = tempdir();
    $log = new DebugLog($dir . '/run', $dir . '/debug.json');
    file_put_contents($dir . '/debug.json', str_repeat(' ', 5000) . '{"version":1,"enabled":true}');
    eq($log->enabled(), false);
    unlink($dir . '/debug.json');
    posix_mkfifo($dir . '/debug.json', 0600);
    eq($log->enabled(), false);
    unlink($dir . '/debug.json');
    $log->setEnabled(true);
    unlink($dir . '/run/debug.log');
    posix_mkfifo($dir . '/run/debug.log', 0600);
    $log->record('test.special_file');
    ok(!str_contains($log->download(), 'test.special_file'));
});
