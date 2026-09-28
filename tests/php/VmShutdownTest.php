<?php
use DeadlockGuard\CommandRunner;
use DeadlockGuard\NativePlatform;
use DeadlockGuard\Runner;
use DeadlockGuard\Store;
use DeadlockGuard\UncertainOperation;

// Run the real CLI helper next to a disposable copy of Unraid's wrapper interface.
function vmShutdownFixture(string $mode = 'success'): array
{
    $plugins = tempdir();
    $plugin = $plugins . '/deadlock-guard';
    $native = $plugins . '/dynamix.vm.manager/include';
    mkdir($plugin . '/scripts', 0700, true);
    mkdir($plugin . '/lib', 0700, true);
    mkdir($native, 0700, true);
    $source = dirname(__DIR__, 2) . '/src/usr/local/emhttp/plugins/deadlock-guard';
    $helper = $source . '/scripts/vm-shutdown.php';
    ok(is_file($helper), 'Native VM shutdown helper is missing');
    copy($helper, $plugin . '/scripts/vm-shutdown.php');
    foreach (['bootstrap.php', 'Config.php'] as $file) {
        copy($source . '/lib/' . $file, $plugin . '/lib/' . $file);
    }
    copy(__DIR__ . '/support/native-libvirt.php', $native . '/libvirt.php');
    file_put_contents($native . '/settings.json', json_encode(['mode' => $mode]));
    return [$plugin . '/scripts/vm-shutdown.php', $native];
}

function vmShutdownCalls(string $native): array
{
    return array_map(
        fn($line) => json_decode($line, true),
        file($native . '/calls.jsonl', FILE_IGNORE_NEW_LINES) ?: [],
    );
}

test('native VM stop uses Unraid PHP shutdown with a domain pinned by UUID', function () {
    [$helper, $native] = vmShutdownFixture();
    $runner = new class ($helper) implements CommandRunner {
        public function __construct(private string $helper) {}
        public function run(
            array $argv,
            float $timeout = 10,
            ?string $input = null,
            bool $mutation = false,
        ): string {
            eq($argv[0], '/usr/bin/php');
            ok($mutation, 'A lost shutdown response must retain the reservation');
            ok($timeout > 0 && $timeout <= 15, 'Only the shutdown request belongs in this command');
            $argv[0] = PHP_BINARY;
            $helperIndex = array_search(
                dirname(__DIR__, 2) .
                    '/src/usr/local/emhttp/plugins/deadlock-guard/scripts/vm-shutdown.php',
                $argv,
                true,
            );
            ok($helperIndex !== false, 'NativePlatform must invoke the shutdown helper');
            $argv[$helperIndex] = $this->helper;
            return (new Runner())->run($argv, $timeout, $input, $mutation);
        }
    };
    $store = new Store(tempdir() . '/run', tempdir() . '/config.json');
    $uuid = '9b400089-e8b1-84e4-1481-7c8089110133';
    (new NativePlatform($store, $runner))->stop(member('vm', $uuid), 120);
    eq(vmShutdownCalls($native), [['connect', 'qemu:///system'], ['lookup', $uuid], ['shutdown']]);
});

test('native VM shutdown errors reach the worker instead of reporting acceptance', function () {
    foreach (
        [
            ['unavailable', 'Connection refused', 1],
            ['missing', 'Domain not found', 2],
            ['rejected', 'Guest shutdown rejected', 3],
        ]
        as [$mode, $message, $calls]
    ) {
        [$helper, $native] = vmShutdownFixture($mode);
        $caught = null;
        try {
            (new Runner())->run(
                [
                    PHP_BINARY,
                    '-d',
                    'auto_prepend_file=',
                    '-d',
                    'short_open_tag=1',
                    $helper,
                    '9b400089-e8b1-84e4-1481-7c8089110133',
                ],
                mutation: true,
            );
        } catch (Throwable $error) {
            $caught = $error;
        }
        ok($caught instanceof UncertainOperation, 'Do not clear an uncertain shutdown request');
        ok(str_contains($caught->getMessage(), $message), 'Preserve the native error');
        eq(count(vmShutdownCalls($native)), $calls);
    }
});

test('native shutdown rejects malformed UUIDs before connecting to libvirt', function () {
    [$helper, $native] = vmShutdownFixture();
    raises(
        fn() => (new Runner())->run([
            PHP_BINARY,
            '-d',
            'auto_prepend_file=',
            '-d',
            'short_open_tag=1',
            $helper,
            'Windows; touch /tmp/should-not-exist',
        ]),
        'Invalid workload identifier',
    );
    ok(!file_exists($native . '/calls.jsonl'), 'An invalid UUID must not reach libvirt');
});

test('native VM stop cannot advance a handoff without shutdown and resource release', function () {
    foreach (['running', 'shut off', 'rejected'] as $outcome) {
        [$store, $unused, $job, $conflict, $target] = scenario('vm', 'docker');
        [$helper] = vmShutdownFixture($outcome === 'rejected' ? 'rejected' : 'success');
        $runner = new class ($helper, $outcome) implements CommandRunner {
            public bool $submitted = false;
            public function __construct(private string $helper, private string $outcome) {}
            public function run(
                array $argv,
                float $timeout = 10,
                ?string $input = null,
                bool $mutation = false,
            ): string {
                if ($argv[0] === 'virsh' && $argv[3] === 'dominfo') {
                    $state =
                        $this->submitted && $this->outcome === 'shut off' ? 'shut off' : 'running';
                    return "UUID: 11111111-1111-1111-1111-111111111111\nName: Windows\nState: $state\n";
                }
                if ($argv[0] === 'docker' && $argv[1] === 'inspect') {
                    return json_encode([
                        [
                            'Id' => str_repeat('b', 64),
                            'Name' => '/b',
                            'State' => ['Status' => 'exited', 'Running' => false, 'Pid' => 0],
                        ],
                    ]);
                }
                eq($argv[0], '/usr/bin/php');
                $scriptIndex = array_search(
                    dirname(__DIR__, 2) .
                        '/src/usr/local/emhttp/plugins/deadlock-guard/scripts/vm-shutdown.php',
                    $argv,
                    true,
                );
                ok($scriptIndex !== false, 'No start or forced stop may occur');
                ok(!$this->submitted, 'Only one shutdown request may be sent');
                $this->submitted = true;
                $argv[0] = PHP_BINARY;
                $argv[$scriptIndex] = $this->helper;
                return (new Runner())->run($argv, $timeout, $input, $mutation);
            }
        };
        (new DeadlockGuard\Coordinator($store, new NativePlatform($store, $runner)))->run(
            $job['id'],
        );
        $result = $store->job($job['id']);
        ok($runner->submitted);
        eq($result['states']['docker:b']['status'], 'stopped');
        eq($result['status'], $outcome === 'rejected' ? 'quarantined' : 'failed');
        if ($outcome === 'rejected') {
            eq($result['inFlight']['action'], 'stop');
            raises(
                fn() => (new DeadlockGuard\Jobs($store))->submit(
                    [['workload' => $conflict, 'action' => 'start']],
                    'opposing-after-vm-stop',
                ),
                'busy',
            );
        } else {
            eq($result['inFlight'], null);
            ok(str_contains($result['error'], 'Shutdown timeout'));
        }
    }
});
