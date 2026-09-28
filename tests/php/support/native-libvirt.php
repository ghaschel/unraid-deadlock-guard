<?php
// Emulate the installed Unraid wrapper, including its required short PHP tags.
?>
<?
class Libvirt
{
    private $domain;
    private array $settings;

    public function __construct(string $uri)
    {
        $this->settings = json_decode(file_get_contents(__DIR__ . '/settings.json'), true);
        $this->record(['connect', $uri]);
    }

    private function record(array $call): void
    {
        file_put_contents(__DIR__ . '/calls.jsonl', json_encode($call) . "\n", FILE_APPEND);
    }

    public function enabled(): bool
    {
        return $this->settings['mode'] !== 'unavailable';
    }

    public function domain_get_domain_by_uuid(string $uuid)
    {
        $this->record(['lookup', $uuid]);
        if ($this->settings['mode'] === 'missing') {
            return false;
        }
        $this->domain = fopen('php://memory', 'r+');
        return $this->domain;
    }

    public function domain_shutdown($domain): bool
    {
        if (!is_resource($domain) || $domain !== $this->domain) {
            throw new RuntimeException('Shutdown must use the domain resolved by UUID');
        }
        $this->record(['shutdown']);
        return $this->settings['mode'] !== 'rejected';
    }

    public function get_last_error(): string
    {
        return match ($this->settings['mode']) {
            'unavailable' => 'Connection refused',
            'missing' => 'Domain not found',
            'rejected' => 'Guest shutdown rejected',
            default => '',
        };
    }
}
