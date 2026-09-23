<?php
declare(strict_types=1);
namespace DeadlockGuard;

use RuntimeException;

final class ApiIntegration
{
    public const SUPPORTED_VERSION = '4.37.4';

    private string $module;
    public function __construct(private Store $store, ?string $module = null)
    {
        $this->module = $module ?? dirname(__DIR__) . '/api-plugin';
    }

    public function hash(): string
    {
        $files = glob($this->module . '/*.mjs') ?: [];
        sort($files);
        if (!$files) {
            throw new RuntimeException('API adapter files are missing');
        }
        $lines = [];
        foreach ($files as $file) {
            $lines[] = basename($file) . ':' . hash_file('sha256', $file);
        }
        return hash('sha256', implode("\n", $lines));
    }

    public function health(): array
    {
        try {
            $error = $this->store->runDir . '/api-install-error.json';
            if (is_file($error)) {
                return [
                    'ready' => false,
                    'message' =>
                        'API setup failed: ' .
                        (Store::read($error)['error'] ?? 'Unknown error') .
                        '. See Troubleshooting.',
                ];
            }
            $path = $this->store->runDir . '/api-integration.json';
            if (!is_file($path)) {
                return [
                    'ready' => false,
                    'message' =>
                        'API integration is not active. Restart the Unraid API service to load it. See Troubleshooting.',
                ];
            }
            $record = Store::read($path);
            if (!ProcessIdentity::alive($record)) {
                return [
                    'ready' => false,
                    'message' => 'API integration is not running. Start the Unraid API service.',
                ];
            }
            if (!empty($record['error'])) {
                return [
                    'ready' => false,
                    'message' => 'API integration unavailable: ' . $record['error'],
                ];
            }
            if (($record['apiVersion'] ?? '') !== self::SUPPORTED_VERSION) {
                return ['ready' => false, 'message' => 'This beta supports Unraid API 4.37.4.'];
            }
            if (($record['hash'] ?? '') !== $this->hash()) {
                return [
                    'ready' => false,
                    'message' => 'API integration needs to reload. Restart the Unraid API service.',
                ];
            }
            return ['ready' => true, 'message' => 'API handoffs installed and activated'];
        } catch (\Throwable $error) {
            return [
                'ready' => false,
                'message' => 'Unable to check API integration: ' . $error->getMessage(),
            ];
        }
    }

    public function assertReady(string $adapterHash): void
    {
        $health = $this->health();
        if (!$health['ready']) {
            throw new RuntimeException($health['message']);
        }
        if (!hash_equals($this->hash(), $adapterHash)) {
            throw new RuntimeException('API adapter changed; restart the Unraid API service');
        }
    }
}
