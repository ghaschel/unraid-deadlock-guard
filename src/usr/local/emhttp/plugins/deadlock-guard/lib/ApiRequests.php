<?php
declare(strict_types=1);
namespace DeadlockGuard;

use Closure;
use RuntimeException;

/** Local-only bridge. The authenticated Unraid API supplies resource permissions. */
final class ApiRequests
{
    public function __construct(
        private Store $store,
        private Handoffs $handoffs,
        private Closure $inventory,
    ) {}

    public function handle(array $request): array
    {
        if (($request['op'] ?? '') === 'status') {
            return ['job' => Jobs::publicJob($this->store->job($request['id'] ?? ''))];
        }
        if (($request['op'] ?? '') !== 'route') {
            throw new RuntimeException('Invalid API bridge operation');
        }

        $allowed = $request['allowedTypes'] ?? null;
        if (
            !is_array($allowed) ||
            !array_is_list($allowed) ||
            count($allowed) > 2 ||
            array_filter($allowed, fn($type) => !in_array($type, ['docker', 'vm'], true))
        ) {
            throw new RuntimeException('Invalid API resource permissions');
        }
        $batch = $request['batch'] ?? null;
        if (
            !is_array($batch) ||
            !array_is_list($batch) ||
            !$batch ||
            count($batch) > Config::MAX_REQUESTS
        ) {
            throw new RuntimeException('Invalid API operation batch');
        }
        $native = $request['native'] ?? null;
        if (!is_array($native) || !in_array($native, $batch, true)) {
            throw new RuntimeException('API operation is absent from its batch');
        }

        $config = $this->store->config();
        $router = new Router($config, ($this->inventory)());
        $requests = [];
        foreach ($batch as $action) {
            if (!is_array($action) || isset($action['bulk'])) {
                throw new RuntimeException('Invalid API batch action');
            }
            $requests = array_merge($requests, $router->route($action, 'api')['requests']);
        }
        if ($requests) {
            Config::plan($config, $requests, 'api');
        }

        $route = $router->route($native, 'api');
        $this->store->debug->record('api.routed', [
            'managed' => $route['managed'],
            'source' => 'api',
        ]);
        if (!$route['managed']) {
            return $route;
        }
        $job = $this->handoffs->submit(
            $route['requests'],
            $request['key'] ?? '',
            source: 'api',
            allowedTypes: $allowed,
            batchRequests: $requests,
        );
        return $route + ['job' => Jobs::publicJob($job)];
    }
}
