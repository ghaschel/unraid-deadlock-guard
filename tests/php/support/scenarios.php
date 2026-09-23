<?php
use DeadlockGuard\Config;
use DeadlockGuard\Store;
use DeadlockGuard\Jobs;
use DeadlockGuard\Handoffs;
use DeadlockGuard\Lifecycle;

function scenario(string $from, string $to, array $extra = []): array
{
    $ids = ['vm' => '11111111-1111-1111-1111-111111111111', 'docker' => 'a'];
    $conflict = member($from, $ids[$from]);
    $target = member($to, $to === 'vm' ? '22222222-2222-2222-2222-222222222222' : 'b');
    $directory = tempdir();
    $store = new Store($directory . '/run', $directory . '/config.json');
    $store->saveConfig(
        config([
            group(
                'gpu',
                [$conflict, $target],
                array_merge(['vmTimeout' => 1, 'containerTimeout' => 1], $extra),
            ),
        ]),
    );
    $platform = new SimulatedPlatform([$conflict, $target]);
    $platform->states[Config::key($conflict)]['status'] = 'running';
    $job = (new Jobs($store))->submit([['workload' => $target, 'action' => 'start']], 'scenario-1');
    return [$store, $platform, $job, $conflict, $target];
}

function readyHandoffs(Store $store, SimulatedPlatform $platform, array &$launched): Handoffs
{
    $directory = dirname($store->runDir);
    @mkdir($directory . '/etc', 0755, true);
    file_put_contents($directory . '/etc/unraid-version', "version=\"7.3.2\"\n");
    Store::atomic(dirname($store->configFile) . '/installed.json', ['installed' => true]);
    return new Handoffs(
        $store,
        $platform,
        new Lifecycle($store, $directory, fn() => null),
        function ($id) use (&$launched) {
            $launched[] = $id;
        },
    );
}
