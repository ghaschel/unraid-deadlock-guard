<?php
declare(strict_types=1);
namespace DeadlockGuard;

use RuntimeException;

final class Config
{
    public const VERSION = 1;
    public const MAX_GROUPS = 128;
    public const MAX_MEMBERS = 128;
    public const MAX_REQUESTS = 128;
    public const VM_TIMEOUT = 120;
    public const CONTAINER_TIMEOUT = 30;
    public const MAX_SHUTDOWN_TIMEOUT = 1800;

    public static function member(mixed $member): array
    {
        if (
            !is_array($member) ||
            !isset($member['type'], $member['id']) ||
            !is_string($member['id'])
        ) {
            throw new RuntimeException('Invalid workload');
        }
        $valid = match ($member['type']) {
            'vm' => preg_match(
                '/^[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}$/D',
                $member['id'],
            ),
            'docker' => preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,254}$/D', $member['id']),
            default => false,
        };
        if (!$valid) {
            throw new RuntimeException('Invalid workload identifier');
        }
        return [
            'type' => $member['type'],
            'id' => $member['type'] === 'vm' ? strtolower($member['id']) : $member['id'],
        ];
    }

    public static function key(array $member): string
    {
        return $member['type'] . ':' . $member['id'];
    }

    public static function validate(mixed $input): array
    {
        if (!is_array($input) || ($input['version'] ?? null) !== self::VERSION) {
            throw new RuntimeException('Unsupported configuration version');
        }
        if (
            !isset($input['groups']) ||
            !is_array($input['groups']) ||
            !array_is_list($input['groups']) ||
            count($input['groups']) > self::MAX_GROUPS
        ) {
            throw new RuntimeException('Invalid groups');
        }
        $groups = [];
        $ids = [];
        foreach ($input['groups'] as $group) {
            if (
                !is_array($group) ||
                !is_string($group['id'] ?? null) ||
                !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $group['id'])
            ) {
                throw new RuntimeException('Invalid group ID');
            }
            if (isset($ids[$group['id']])) {
                throw new RuntimeException('Duplicate group ID');
            }
            $ids[$group['id']] = true;
            if (
                !is_string($group['name'] ?? null) ||
                trim($group['name']) === '' ||
                strlen($group['name']) > 120 ||
                preg_match('/[\x00-\x1f]/', $group['name'])
            ) {
                throw new RuntimeException('Invalid group name');
            }
            if (!is_bool($group['enabled'] ?? null)) {
                throw new RuntimeException('Invalid enabled flag');
            }
            if (
                !is_array($group['members'] ?? null) ||
                !array_is_list($group['members']) ||
                count($group['members']) < 2 ||
                count($group['members']) > self::MAX_MEMBERS
            ) {
                throw new RuntimeException('Invalid group members (need 2–128)');
            }
            $members = [];
            foreach ($group['members'] as $member) {
                $member = self::member($member);
                $key = self::key($member);
                if (isset($members[$key])) {
                    throw new RuntimeException('Duplicate group member');
                }
                $members[$key] = $member;
            }
            $result = [
                'id' => $group['id'],
                'name' => trim($group['name']),
                'enabled' => $group['enabled'],
                'members' => array_values($members),
            ];
            foreach (['webui' => true, 'api' => true] as $source => $default) {
                $value = array_key_exists($source, $group) ? $group[$source] : $default;
                if (!is_bool($value)) {
                    throw new RuntimeException('Invalid handoff source: ' . $source);
                }
                $result[$source] = $value;
            }
            if (!$result['webui'] && !$result['api']) {
                throw new RuntimeException(
                    'Select WebUI, API, or both for group: ' . $result['name'],
                );
            }
            foreach (
                ['vmTimeout' => self::VM_TIMEOUT, 'containerTimeout' => self::CONTAINER_TIMEOUT]
                as $field => $default
            ) {
                $value = $group[$field] ?? $default;
                if (!is_int($value) || $value < 1 || $value > self::MAX_SHUTDOWN_TIMEOUT) {
                    throw new RuntimeException('Invalid shutdown timeout (1–1800 seconds)');
                }
                $result[$field] = $value;
            }
            foreach (['forceVm', 'forceContainer'] as $field) {
                $value = $group[$field] ?? false;
                if (!is_bool($value)) {
                    throw new RuntimeException('Invalid force-stop flag');
                }
                $result[$field] = $value;
            }
            $groups[] = $result;
        }
        return ['version' => self::VERSION, 'groups' => $groups];
    }

    public static function groupsFor(array $config, array $member, ?string $source = null): array
    {
        if ($source !== null) {
            self::source($source);
        }
        return array_values(
            array_filter(
                $config['groups'],
                static fn($group) => $group['enabled'] &&
                    ($source === null || ($group[$source] ?? true)) &&
                    in_array($member, $group['members'], true),
            ),
        );
    }

    public static function source(string $source): string
    {
        if (!in_array($source, ['webui', 'api'], true)) {
            throw new RuntimeException('Invalid handoff source');
        }
        return $source;
    }

    public static function assertPermissions(array $plan, ?array $allowedTypes): void
    {
        if ($allowedTypes === null) {
            return;
        }
        $members = array_merge(array_column($plan['requests'], 'workload'), $plan['conflicts']);
        foreach ($members as $member) {
            if (!in_array($member['type'], $allowedTypes, true)) {
                throw new RuntimeException(
                    'API permission required to manage ' .
                        ($member['type'] === 'vm' ? 'VMs' : 'Docker containers') .
                        ' affected by this handoff',
                );
            }
        }
    }

    public static function requests(mixed $requests): array
    {
        if (
            !is_array($requests) ||
            !array_is_list($requests) ||
            !$requests ||
            count($requests) > self::MAX_REQUESTS
        ) {
            throw new RuntimeException('Invalid request list');
        }
        $result = [];
        foreach ($requests as $request) {
            if (
                !is_array($request) ||
                !in_array($request['action'] ?? null, ['start', 'restart', 'resume', 'wake'], true)
            ) {
                throw new RuntimeException('Invalid action');
            }
            $member = self::member($request['workload'] ?? null);
            if ($request['action'] === 'wake' && $member['type'] !== 'vm') {
                throw new RuntimeException('Invalid wake action');
            }
            $key = self::key($member);
            if (isset($result[$key])) {
                throw new RuntimeException('Duplicate requested workload');
            }
            $result[$key] = ['workload' => $member, 'action' => $request['action']];
        }
        ksort($result);
        return array_values($result);
    }

    public static function plan(array $config, array $requests, string $source = 'webui'): array
    {
        $source = self::source($source);
        $requests = self::requests($requests);
        $groups = [];
        $conflicts = [];
        $targetsByKey = [];
        foreach ($requests as $request) {
            $targetsByKey[self::key($request['workload'])] = $request['workload'];
        }
        foreach ($config['groups'] as $group) {
            if (!$group['enabled'] || !($group[$source] ?? true)) {
                continue;
            }
            $targets = array_filter(
                $group['members'],
                fn($member) => isset($targetsByKey[self::key($member)]),
            );
            if (count($targets) > 1) {
                throw new RuntimeException(
                    'Choose one member of exclusive group: ' . $group['name'],
                );
            }
            if (!$targets) {
                continue;
            }
            $groups[] = $group['id'];
            foreach ($group['members'] as $member) {
                if (!isset($targetsByKey[self::key($member)])) {
                    $conflicts[self::key($member)] = $member;
                }
            }
        }
        sort($groups);
        ksort($conflicts);
        return [
            'requests' => $requests,
            'groups' => $groups,
            'conflicts' => array_values($conflicts),
        ];
    }

    public static function policy(array $config, array $plan, array $member): array
    {
        $isVm = $member['type'] === 'vm';
        $timeout = 0;
        $force = true;
        foreach (self::groupsFor($config, $member) as $group) {
            if (in_array($group['id'], $plan['groups'], true)) {
                $timeout = max($timeout, $group[$isVm ? 'vmTimeout' : 'containerTimeout']);
                $force = $force && $group[$isVm ? 'forceVm' : 'forceContainer'];
            }
        }
        return [
            'timeout' => $timeout ?: ($isVm ? self::VM_TIMEOUT : self::CONTAINER_TIMEOUT),
            'force' => $timeout > 0 && $force,
        ];
    }
}
