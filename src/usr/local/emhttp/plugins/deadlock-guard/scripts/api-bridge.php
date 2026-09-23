<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    exit(1);
}
require dirname(__DIR__) . '/lib/bootstrap.php';
use DeadlockGuard\ApiIntegration;
use DeadlockGuard\ApiRequests;
use DeadlockGuard\Handoffs;
use DeadlockGuard\Lifecycle;
use DeadlockGuard\NativePlatform;
use DeadlockGuard\Security;
use DeadlockGuard\Store;

try {
    $raw = stream_get_contents(STDIN, Security::MAX_REQUEST_BYTES + 1);
    if ($raw === false || strlen($raw) > Security::MAX_REQUEST_BYTES) {
        throw new RuntimeException('API bridge request is too large');
    }
    $request = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($request) || !is_string($request['adapterHash'] ?? null)) {
        throw new RuntimeException('Invalid API bridge request');
    }
    $store = Store::system();
    if (!is_file(dirname($store->configFile) . '/installed.json')) {
        throw new RuntimeException('Deadlock Guard is not installed');
    }
    (new ApiIntegration($store))->assertReady($request['adapterHash']);
    $platform = new NativePlatform($store);
    $handoffs = new Handoffs($store, $platform, new Lifecycle($store));
    $bridge = new ApiRequests($store, $handoffs, fn() => $platform->inventory());
    echo json_encode($bridge->handle($request), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    echo json_encode(['error' => $error->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
    exit(1);
}
