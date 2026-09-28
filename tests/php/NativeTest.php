<?php
use DeadlockGuard\Runner;
use DeadlockGuard\NativePlatform;
use DeadlockGuard\CommandRunner;
use DeadlockGuard\Store;
use DeadlockGuard\Config;
class FixtureRunner implements CommandRunner
{
    public array $calls = [];
    public array $deadlines = [];
    public string $signal = 'SIGTERM';
    public string $id;
    public bool $running = true;
    public function __construct()
    {
        $this->id = str_repeat('a', 64);
    }

    public function run(
        array $argv,
        float $timeout = 10,
        ?string $input = null,
        bool $mutation = false,
    ): string {
        $this->calls[] = $argv;
        $this->deadlines[] = $timeout;
        if ($argv[0] === 'docker' && $argv[1] === 'inspect') {
            return json_encode([
                [
                    'Id' => $this->id,
                    'Name' => '/FileFlows',
                    'Config' => ['StopSignal' => $this->signal, 'Cmd' => ['/app/start']],
                    'HostConfig' => [
                        'RestartPolicy' => ['Name' => 'no'],
                        'NetworkMode' => 'bridge',
                    ],
                    'State' => [
                        'Running' => $this->running,
                        'Restarting' => false,
                        'Paused' => false,
                        'Status' => $this->running ? 'running' : 'exited',
                        'Pid' => $this->running ? 123 : 0,
                    ],
                ],
            ]);
        }
        if ($argv[0] === 'docker' && in_array($argv[1], ['kill', 'stop'], true)) {
            return $this->id;
        }
        throw new RuntimeException('Unexpected command ' . json_encode($argv));
    }
}

test('process runner preserves literal arguments and reports errors and deadlines', function () {
    $runner = new Runner();
    $literal = 'x; touch /tmp/nope $(echo nope)';
    eq($runner->run([PHP_BINARY, '-r', 'echo $argv[1];', $literal]), $literal);
    raises(
        fn() => $runner->run([PHP_BINARY, '-r', 'fwrite(STDERR,"bad command");exit(2);']),
        'bad command',
    );
    raises(
        fn() => $runner->run([PHP_BINARY, '-r', 'sleep(4);'], timeout: 0.05, mutation: true),
        'timed out',
    );
});

test('native Docker stop delegates timeout and stop signal handling to Docker', function () {
    $store = new Store(tempdir() . '/run', tempdir() . '/config.json');
    $runner = new FixtureRunner();
    $platform = new NativePlatform($store, $runner);
    $member = member('docker', 'FileFlows');
    foreach (['', 'SIGINT', 'SIGKILL'] as $signal) {
        $runner->signal = $signal;
        $platform->stop($member, 60);
        eq(end($runner->calls), ['docker', 'stop', '--timeout', '60', '--', str_repeat('a', 64)]);
        ok(end($runner->deadlines) > 60, 'Client must allow Docker its full shutdown timeout');
        ok(end($runner->deadlines) <= 120, 'Client must have a bounded completion deadline');
    }
});

test('container recreation during a job is rejected rather than switching identity', function () {
    $store = new Store(tempdir() . '/run', tempdir() . '/config.json');
    $runner = new FixtureRunner();
    $platform = new NativePlatform($store, $runner);
    $member = member('docker', 'FileFlows');
    $platform->inspect($member);
    $runner->id = str_repeat('b', 64);
    raises(fn() => $platform->inspect($member), 'changed');
    $new = new NativePlatform($store, $runner);
    eq($new->inspect($member)['runtimeId'], str_repeat('b', 64));
});

test('hook XML parsing rejects entities and takes UUID from domain XML', function () {
    $xml =
        '<domain><name>Untrusted &amp; name</name><uuid>11111111-1111-1111-1111-111111111111</uuid></domain>';
    eq(DeadlockGuard\Gate::vmFromXml($xml), member('vm', '11111111-1111-1111-1111-111111111111'));
    raises(
        fn() => DeadlockGuard\Gate::vmFromXml(
            '<!DOCTYPE x [<!ENTITY secret SYSTEM "file:///etc/passwd">]><domain/>',
        ),
        'Invalid',
    );
});

test(
    'lost mutating command responses quarantine instead of reporting a definite failure',
    function () {
        $runner = new DeadlockGuard\Runner();
        $caught = null;
        try {
            $runner->run(
                [PHP_BINARY, '-r', 'fwrite(STDERR,"transport disconnected");exit(1);'],
                timeout: 2,
                mutation: true,
            );
        } catch (Throwable $error) {
            $caught = $error;
        }
        ok(
            $caught instanceof DeadlockGuard\UncertainOperation,
            'Nonzero mutation may have been accepted by the daemon',
        );
    },
);

test('VM console metadata includes the domain name for native console titles', function () {
    $store = new Store(tempdir() . '/run', tempdir() . '/config.json');
    $runner = new class implements CommandRunner {
        public function run(
            array $argv,
            float $timeout = 10,
            ?string $input = null,
            bool $mutation = false,
        ): string {
            eq($argv[3], 'dumpxml');
            return '<domain><name>Windows &amp; Games</name><devices><graphics type="vnc" port="5901" websocket="5701"/></devices></domain>';
        }
    };
    $platform = new NativePlatform($store, $runner);
    $console = $platform->console(member('vm', '11111111-1111-1111-1111-111111111111'));
    eq($console['name'] ?? null, 'Windows & Games');
    eq($console['protocol'], 'vnc');
    eq($console['port'], 5901);
    eq($console['websocket'], 5701);
});

test('process failures retain the final error after lengthy warnings', function () {
    $runner = new Runner();
    $source =
        'fwrite(STDERR, str_repeat("npm warn peer conflict\n", 200)); fwrite(STDERR, "npm error code ENOTCACHED\nnpm error Missing cached dependency\n"); exit(1);';
    raises(fn() => $runner->run([PHP_BINARY, '-r', $source]), 'npm error code ENOTCACHED');
    eq(
        $runner->run([PHP_BINARY, '-r', 'fwrite(STDERR,"npm warn harmless\n"); echo "success";']),
        'success',
    );
});

test(
    'lost native Docker stop response retains reservations and never starts the target',
    function () {
        [$store, $unused, $job, $conflict, $target] = scenario('docker', 'docker');
        $runner = new class ($store, $job['id']) implements CommandRunner {
            public array $mutations = [];
            public function __construct(private Store $store, private string $jobId) {}
            public function run(
                array $argv,
                float $timeout = 10,
                ?string $input = null,
                bool $mutation = false,
            ): string {
                if ($argv[0] === 'docker' && $argv[1] === 'inspect') {
                    $name = end($argv);
                    $running = $name === 'a';
                    return json_encode([
                        [
                            'Id' => str_repeat($name, 64),
                            'Name' => '/' . $name,
                            'State' => [
                                'Running' => $running,
                                'Restarting' => false,
                                'Paused' => false,
                                'Status' => $running ? 'running' : 'exited',
                                'Pid' => $running ? 123 : 0,
                            ],
                        ],
                    ]);
                }
                $this->mutations[] = $argv[1];
                eq($this->store->job($this->jobId)['inFlight']['action'], 'stop');
                // Model Runner's outcome classification when a mutating client loses its response.
                if ($mutation) {
                    throw new DeadlockGuard\UncertainOperation('Docker stop response lost');
                }
                throw new RuntimeException('Docker stop response lost');
            }
        };
        (new DeadlockGuard\Coordinator($store, new NativePlatform($store, $runner)))->run(
            $job['id'],
        );
        $result = $store->job($job['id']);
        eq($result['status'], 'quarantined');
        eq($result['inFlight']['action'], 'stop');
        eq($result['states'][Config::key($target)]['status'], 'stopped');
        eq($runner->mutations, ['stop']);
        raises(
            fn() => (new DeadlockGuard\Jobs($store))->submit(
                [['workload' => $conflict, 'action' => 'start']],
                'opposing-after-lost-stop',
            ),
            'busy',
        );
    },
);
