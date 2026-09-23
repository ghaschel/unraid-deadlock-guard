<?php
use DeadlockGuard\Store;

function workerFixture(Store $store, string $scenario, bool $inFlight = false): string
{
    $directory = dirname($store->runDir);
    $script = $directory . '/child.php';
    copy(__DIR__ . '/../fixtures/worker.php', $script);
    Store::atomic($directory . '/worker-fixture.json', [
        'bootstrap' =>
            dirname(__DIR__, 3) . '/src/usr/local/emhttp/plugins/deadlock-guard/lib/bootstrap.php',
        'runDir' => $store->runDir,
        'configFile' => $store->configFile,
        'scenario' => $scenario,
        'inFlight' => $inFlight,
    ]);
    return $script;
}
