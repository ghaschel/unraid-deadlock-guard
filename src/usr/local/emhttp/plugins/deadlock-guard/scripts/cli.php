<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
use DeadlockGuard\Store;
use DeadlockGuard\NativePlatform;
use DeadlockGuard\Coordinator;
use DeadlockGuard\Lifecycle;
use DeadlockGuard\PendingJobs;
use DeadlockGuard\WorkerSupervisor;
use DeadlockGuard\ApiInstaller;
if (PHP_SAPI !== 'cli') {
    exit(1);
}
try {
    $store = Store::system();
    $platform = new NativePlatform($store);
    $lifecycle = new Lifecycle($store);
    $command = $argv[1] ?? '';
    $store->debug->record('lifecycle.started', [
        'op' => in_array(
            $command,
            [
                'supervise',
                'worker',
                'install',
                'api-install',
                'activate',
                'services',
                'check',
                'pending',
                'drain',
                'upgrade',
                'remove',
                'debug-on',
                'debug-off',
            ],
            true,
        )
            ? $command
            : 'invalid',
    ]);
    switch ($command) {
        case 'debug-on':
        case 'debug-off':
            $store->debug->setEnabled($command === 'debug-on');
            echo 'Debug logging ' .
                ($command === 'debug-on' ? 'enabled' : 'disabled') .
                ". Logs: /var/run/deadlock-guard/debug*.log\n";
            break;
        case 'supervise':
            (new WorkerSupervisor($store, $platform))->run($argv[2] ?? '');
            break;
        case 'worker':
            $id = $argv[2] ?? '';
            $plan = $store->job($id)['plan'];
            (new Coordinator($store, $platform, fn() => $lifecycle->assertReady($plan)))->run($id);
            break;
        case 'install':
            $restartApi = ($argv[2] ?? '') === '--restart-api';
            if (isset($argv[2]) && !$restartApi) {
                throw new RuntimeException('Unknown installation option');
            }
            $lifecycle->install();
            (new ApiInstaller($store))->attemptInstall(restart: $restartApi);
            break;
        case 'api-install':
            if (!(new ApiInstaller($store))->attemptInstall(restart: true)) {
                throw new RuntimeException('API setup or restart failed. See Troubleshooting.');
            }
            break;
        case 'activate':
            $lifecycle->activate();
            (new ApiInstaller($store))->attemptInstall();
            (new PendingJobs($store, $platform))->check();
            break;
        case 'services':
            $lifecycle->check();
            (new PendingJobs($store, $platform))->check();
            break;
        case 'check':
            $lifecycle->check();
            break;
        case 'pending':
            (new PendingJobs($store, $platform))->check();
            break;
        case 'drain':
            $lifecycle->drain();
            break;
        case 'upgrade':
            $lifecycle->prepareUpgrade(
                isset($argv[2]) && ctype_digit($argv[2]) ? (int) $argv[2] : null,
            );
            break;
        case 'remove':
            $lifecycle->remove();
            (new ApiInstaller($store))->remove();
            break;
        default:
            throw new RuntimeException('Unknown lifecycle command');
    }
    $store->debug->record('lifecycle.completed', ['op' => $command]);
} catch (Throwable $error) {
    DeadlockGuard\DebugLog::system()->record('lifecycle.failed', [
        'errorType' => get_class($error),
    ]);
    fwrite(STDERR, 'Deadlock Guard: ' . $error->getMessage() . "\n");
    exit(1);
}
