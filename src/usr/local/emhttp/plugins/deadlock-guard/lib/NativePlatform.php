<?php
declare(strict_types=1);
namespace DeadlockGuard;

use RuntimeException;
use Throwable;

final class NativePlatform implements Platform
{
    // Docker may need time to finish cleanup after sending SIGKILL.
    private const DOCKER_STOP_COMPLETION_TIMEOUT = 30;
    private array $bindings = [];

    public function __construct(
        private Store $store,
        private CommandRunner $runner = new Runner(),
    ) {}

    public function now(): float
    {
        return hrtime(true) / 1e9;
    }

    public function pause(): void
    {
        usleep(250000);
    }

    public static function vmServiceIdentity(): ?string
    {
        $pid = trim((string) @file_get_contents('/var/run/libvirt/libvirtd.pid'));
        return ctype_digit($pid) ? ProcessIdentity::of((int) $pid) : null;
    }

    private function docker(array $member): array
    {
        $output = $this->runner->run([
            'docker',
            'inspect',
            '--type',
            'container',
            '--',
            $member['id'],
        ]);
        $containers = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
        if (
            !is_array($containers) ||
            count($containers) !== 1 ||
            ($containers[0]['Name'] ?? '') !== '/' . $member['id'] ||
            !preg_match('/^[a-f0-9]{64}$/D', $containers[0]['Id'] ?? '')
        ) {
            throw new RuntimeException('Missing or ambiguous container: ' . $member['id']);
        }
        $container = $containers[0];
        $key = Config::key($member);
        if (isset($this->bindings[$key]) && $this->bindings[$key] !== $container['Id']) {
            throw new RuntimeException(
                'Container identity changed during handoff: ' . $member['id'],
            );
        }
        $this->bindings[$key] = $container['Id'];
        return $container;
    }

    private function virsh(
        string $command,
        array $member,
        bool $mutation = false,
        float $timeout = 10,
    ): string {
        return $this->runner->run(
            ['virsh', '--connect', 'qemu:///system', $command, '--domain', $member['id']],
            timeout: $timeout,
            mutation: $mutation,
        );
    }

    public function inspect(array $member): array
    {
        $member = Config::member($member);
        return $member['type'] === 'docker'
            ? $this->inspectDocker($member)
            : $this->inspectVm($member);
    }

    private function inspectDocker(array $member): array
    {
        $container = $this->docker($member);
        $state = $container['State'] ?? [];
        $stopped =
            in_array($state['Status'] ?? '', ['exited', 'created'], true) &&
            ($state['Running'] ?? null) === false &&
            (int) ($state['Pid'] ?? -1) === 0;
        $status = match (true) {
            !empty($state['Restarting']) => 'restarting',
            !empty($state['Paused']) => 'paused',
            !empty($state['Running']) => 'running',
            $stopped => 'stopped',
            default => 'unknown',
        };
        return [
            'status' => $status,
            'name' => $member['id'],
            'runtimeId' => $container['Id'],
            'release' => 0,
        ];
    }

    private function inspectVm(array $member): array
    {
        $info = [];
        foreach (explode("\n", $this->virsh('dominfo', $member)) as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $info[trim($key)] = trim($value);
            }
        }
        if (strtolower($info['UUID'] ?? '') !== $member['id']) {
            throw new RuntimeException('Missing VM: ' . $member['id']);
        }
        $status = match ($info['State'] ?? '') {
            'shut off' => 'stopped',
            'running' => 'running',
            'paused' => 'paused',
            'pmsuspended' => 'suspended',
            'in shutdown' => 'stopping',
            default => 'unknown',
        };
        $eventPath = $this->store->eventPath($member);
        $event = is_file($eventPath) ? Store::read($eventPath) : [];
        if ($status === 'stopped' && isset($event['phase']) && $event['phase'] !== 'release') {
            $status = 'releasing';
        }
        return [
            'status' => $status,
            'name' => $info['Name'] ?? $member['id'],
            'runtimeId' => $member['id'],
            'release' => $event['release'] ?? 0,
        ];
    }

    public function stop(array $member, int $timeout): void
    {
        if ($member['type'] === 'vm') {
            // Use the same PHP libvirt binding as Unraid's VM Stop control.
            $this->runner->run(
                [
                    '/usr/bin/php',
                    '-d',
                    'auto_prepend_file=',
                    '-d',
                    'short_open_tag=1',
                    dirname(__DIR__) . '/scripts/vm-shutdown.php',
                    $member['id'],
                ],
                mutation: true,
            );
            return;
        }
        $container = $this->docker($member);
        // Match Unraid's Stop operation: Docker handles the configured signal and escalation.
        $this->runner->run(
            ['docker', 'stop', '--timeout', (string) $timeout, '--', $container['Id']],
            timeout: $timeout + self::DOCKER_STOP_COMPLETION_TIMEOUT,
            mutation: true,
        );
    }

    public function forceStopVm(array $member): void
    {
        if ($member['type'] !== 'vm') {
            throw new RuntimeException('Force-stop is only available for VMs');
        }
        $this->virsh('destroy', $member, mutation: true, timeout: 30);
    }

    public function act(array $member, string $action): void
    {
        if ($member['type'] === 'vm') {
            $command = match ($action) {
                'start' => 'start',
                'restart' => 'reboot',
                'resume' => 'resume',
                'wake' => 'dompmwakeup',
                default => throw new RuntimeException('Invalid VM action'),
            };
            $this->virsh($command, $member, mutation: true, timeout: 60);
            return;
        }
        $container = $this->docker($member);
        if (
            ($container['HostConfig']['NetworkMode'] ?? '') === 'host' &&
            ($container['Config']['Cmd'][0] ?? '') === '/opt/unraid/tailscale'
        ) {
            throw new RuntimeException(
                'Unraid disallows Tailscale-enabled containers in host networking mode',
            );
        }
        $command = match ($action) {
            'start' => 'start',
            'resume' => 'unpause',
            default => throw new RuntimeException('Invalid container action'),
        };
        $this->runner->run(
            ['docker', $command, '--', $container['Id']],
            timeout: 60,
            mutation: true,
        );
        $this->runner->run([
            '/usr/bin/php',
            dirname(__DIR__) . '/scripts/docker-route.php',
            $member['id'],
        ]);
    }

    public function inventory(): array
    {
        $items = [];
        $errors = [];
        foreach (['docker', 'vm'] as $type) {
            try {
                foreach ($this->memberIds($type) as $id) {
                    $member = Config::member(['type' => $type, 'id' => $id]);
                    try {
                        $state = $this->inspect($member);
                    } catch (Throwable $error) {
                        $state = [
                            'name' => $id,
                            'status' => 'unknown',
                            'error' => $error->getMessage(),
                        ];
                    }
                    $items[] = array_merge($member, $state);
                }
            } catch (Throwable $error) {
                $errors[$type] = $error->getMessage();
            }
        }
        return ['workloads' => $items, 'errors' => $errors];
    }

    private function memberIds(string $type): array
    {
        $command =
            $type === 'docker'
                ? ['docker', 'ps', '--all', '--format', '{{.Names}}']
                : ['virsh', '--connect', 'qemu:///system', 'list', '--all', '--uuid'];
        return array_filter(array_map('trim', explode("\n", $this->runner->run($command))));
    }

    public function console(array $member): array
    {
        if ($member['type'] !== 'vm') {
            throw new RuntimeException('Console requires a VM');
        }
        $xml = $this->virsh('dumpxml', $member);
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $domain = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
            if (!$domain) {
                throw new RuntimeException('Invalid domain XML');
            }
            foreach ($domain->devices->graphics as $graphics) {
                $protocol = (string) $graphics['type'];
                if (in_array($protocol, ['vnc', 'spice'], true)) {
                    return $this->consoleDetails($graphics, $protocol, (string) $domain->name);
                }
            }
            throw new RuntimeException('VM has no VNC or SPICE console');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    private function consoleDetails(
        \SimpleXMLElement $graphics,
        string $protocol,
        string $name,
    ): array {
        $port = (int) $graphics['port'];
        $websocket = (int) $graphics['websocket'];
        if (
            $port < 1 ||
            $port > 65535 ||
            ($protocol === 'vnc' && ($websocket < 1 || $websocket > 65535))
        ) {
            throw new RuntimeException('VM console is not ready');
        }
        return [
            'protocol' => $protocol,
            'port' => $port,
            'websocket' => $websocket,
            'name' => $name,
        ];
    }
}
