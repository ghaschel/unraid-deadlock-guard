<?php
require dirname(__DIR__, 3) . '/src/usr/local/emhttp/plugins/deadlock-guard/lib/bootstrap.php';

use DeadlockGuard\Store;
use DeadlockGuard\Jobs;

[, $directory, $container, $idempotencyKey] = $argv;
while (!is_file($directory . '/go')) {
    usleep(1000);
}
try {
    $store = new Store($directory . '/run', $directory . '/config');
    $job = (new Jobs($store))->submit(
        [['workload' => ['type' => 'docker', 'id' => $container], 'action' => 'start']],
        $idempotencyKey,
    );
    echo $job['id'];
} catch (Throwable $error) {
    echo $error->getMessage();
}
