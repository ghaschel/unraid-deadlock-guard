<?php
use DeadlockGuard\Runner;
use DeadlockGuard\NativePlatform;
use DeadlockGuard\CommandRunner;
use DeadlockGuard\Store;
use DeadlockGuard\Config;
class FixtureRunner implements CommandRunner
{
    public array $calls = [];
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
        if ($argv[0] === 'docker' && $argv[1] === 'kill') {
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

test(
    'native graceful Docker stop sends configured signal without implicit escalation',
    function () {
        $store = new Store(tempdir() . '/run', tempdir() . '/config.json');
        $runner = new FixtureRunner();
        $platform = new NativePlatform($store, $runner);
        $member = member('docker', 'FileFlows');
        eq($platform->inspect($member)['status'], 'running');
        $platform->stop($member);
        eq(end($runner->calls), [
            'docker',
            'kill',
            '--signal',
            'SIGTERM',
            '--',
            str_repeat('a', 64),
        ]);
        $runner->signal = 'SIGKILL';
        $platform->stop($member);
        eq(end($runner->calls)[3], 'SIGTERM');
        $platform->forceStop($member);
        eq(end($runner->calls)[3], 'SIGKILL');
    },
);

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
