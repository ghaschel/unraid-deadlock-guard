<?php
declare(strict_types=1);
namespace DeadlockGuard;

use RuntimeException;

final class Router
{
    public function __construct(private array $config, private array $inventory) {}

    public function route(array $input, string $source = 'webui'): array
    {
        $source = Config::source($source);
        $type = $input['type'] ?? '';
        $action = $input['action'] ?? '';
        $apiReset = $source === 'api' && $type === 'vm' && $action === 'reset';
        if (
            !in_array($type, ['vm', 'docker'], true) ||
            (!in_array($action, ['start', 'restart', 'resume', 'wake'], true) && !$apiReset) ||
            ($type === 'docker' && $action === 'wake')
        ) {
            throw new RuntimeException('Invalid native action');
        }
        if (isset($this->inventory['errors'][$type])) {
            throw new RuntimeException($this->inventory['errors'][$type]);
        }
        $items = array_values(
            array_filter($this->inventory['workloads'], fn($member) => $member['type'] === $type),
        );
        $requests = [];
        if (($input['bulk'] ?? false) === true) {
            if (!in_array($action, ['start', 'resume'], true)) {
                throw new RuntimeException('Invalid bulk action');
            }
            foreach ($items as $member) {
                if ($action === 'start' && $member['status'] === 'running') {
                    continue;
                }
                if ($action === 'resume' && $member['status'] !== 'paused') {
                    continue;
                }
                $requests[] = ['workload' => Config::member($member), 'action' => $action];
            }
        } else {
            $id = $input['id'] ?? null;
            if (!is_string($id) || strlen($id) > 255) {
                throw new RuntimeException('Invalid workload reference');
            }
            $matches = array_values(
                array_filter(
                    $items,
                    fn($member) => $type === 'vm'
                        ? $member['id'] === strtolower($id)
                        : $member['id'] === $id ||
                            (preg_match('/^[a-f0-9]{12,64}$/D', $id) &&
                                str_starts_with($member['runtimeId'] ?? '', $id)),
                ),
            );
            if (count($matches) !== 1) {
                throw new RuntimeException(
                    'Missing or ambiguous workload; refresh the page and repair its group if renamed',
                );
            }
            // Native API reset destroys and recreates the domain. Reject grouped
            // resets before destruction: its later create would fail the VM gate.
            if ($apiReset) {
                if (Config::groupsFor($this->config, Config::member($matches[0]))) {
                    throw new RuntimeException(
                        'Reset is not supported for grouped VMs. Use Reboot or Stop then Start.',
                    );
                }
                return ['managed' => false, 'requests' => []];
            }
            // The API's VM start/resume both mean transition to RUNNING.
            if (
                $source === 'api' &&
                $type === 'vm' &&
                in_array($action, ['start', 'resume'], true)
            ) {
                $action = $matches[0]['status'] === 'paused' ? 'resume' : 'start';
            }
            $requests[] = ['workload' => Config::member($matches[0]), 'action' => $action];
        }
        $managed = false;
        foreach ($requests as $request) {
            $member = $request['workload'];
            if (Config::groupsFor($this->config, $member, $source)) {
                $managed = true;
            }
            // An unchecked source skips handoffs, but a grouped VM still needs
            // a single-use start permit to pass the independently installed hook.
            // API reboot also stays on the guest-reboot path; the native API
            // otherwise shuts down and recreates the VM without a permit.
            if (
                $member['type'] === 'vm' &&
                ($request['action'] === 'start' ||
                    ($source === 'api' && $request['action'] === 'restart')) &&
                Config::groupsFor($this->config, $member)
            ) {
                $managed = true;
            }
        }
        if ($managed) {
            Config::plan($this->config, Config::requests($requests), $source);
        }
        return ['managed' => $managed, 'requests' => $requests];
    }
}
