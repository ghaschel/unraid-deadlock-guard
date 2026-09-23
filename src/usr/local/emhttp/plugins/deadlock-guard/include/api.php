<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';

use DeadlockGuard\Store;
use DeadlockGuard\Security;
use DeadlockGuard\Jobs;
use DeadlockGuard\Config;
use DeadlockGuard\NativePlatform;
use DeadlockGuard\Lifecycle;
use DeadlockGuard\Router;
use DeadlockGuard\PendingJobs;
use DeadlockGuard\Handoffs;
use DeadlockGuard\ApiIntegration;

function submitJob(array $requests, string $key, Handoffs $handoffs): array
{
    $requests = Config::requests($requests);
    $job = $handoffs->submit($requests, $key);
    return ['managed' => true, 'job' => Jobs::publicJob($job), 'requests' => $requests];
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    $request = Security::request();
    $store = Store::system();
    $platform = new NativePlatform($store);
    $lifecycle = new Lifecycle($store);
    $handoffs = new Handoffs($store, $platform, $lifecycle);

    switch ($request['op'] ?? '') {
        case 'snapshot':
            $response = [
                'config' => $store->config(),
                'revision' => $store->revision(),
                'inventory' => $platform->inventory(),
                'health' => $lifecycle->health(),
                'apiHealth' => (new ApiIntegration($store))->health(),
                'jobs' => array_map([Jobs::class, 'publicJob'], $store->jobs()),
            ];
            break;
        case 'inventory':
            $response = $platform->inventory();
            break;
        case 'config':
            if (!is_string($request['revision'] ?? null)) {
                throw new RuntimeException('Configuration revision required');
            }
            $response = [
                'config' => $store->saveConfig($request['config'] ?? [], $request['revision']),
                'revision' => $store->revision(),
            ];
            break;
        case 'route':
            $route = (new Router($store->config(), $platform->inventory()))->route(
                $request['native'] ?? [],
            );
            $response = $route['managed']
                ? submitJob($route['requests'], $request['key'] ?? '', $handoffs)
                : $route;
            break;
        case 'job':
            $response = submitJob($request['requests'] ?? [], $request['key'] ?? '', $handoffs);
            break;
        case 'status':
            $response = ['job' => Jobs::publicJob($store->job($request['id'] ?? ''))];
            break;
        case 'history':
            $response = ['jobs' => array_map([Jobs::class, 'publicJob'], $store->jobs())];
            break;
        case 'console':
            $response = $platform->console(Config::member($request['workload'] ?? []));
            break;
        case 'integration':
            $lifecycle->check();
            $response = [
                'health' => $lifecycle->health(),
                'apiHealth' => (new ApiIntegration($store))->health(),
            ];
            break;
        case 'pending':
            (new PendingJobs($store, $platform))->check();
            $response = ['jobs' => array_map([Jobs::class, 'publicJob'], $store->jobs())];
            break;
        default:
            throw new RuntimeException('Unknown endpoint operation', 400);
    }
    echo json_encode($response, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    $code = $error->getCode();
    http_response_code(in_array($code, [400, 401, 403, 405, 413], true) ? $code : 409);
    echo json_encode(['error' => $error->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
}
