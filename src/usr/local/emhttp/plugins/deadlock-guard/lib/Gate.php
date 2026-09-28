<?php
declare(strict_types=1);
namespace DeadlockGuard;

use RuntimeException;
/** Intentionally independent of the Platform and command runner. Never call libvirt here. */
final class Gate
{
    private const AUTHORIZATION_SECONDS = 30;
    public const MAX_XML_BYTES = 4 * 1024 * 1024;

    public function __construct(private Store $store) {}

    public function issue(array $member, string $jobId): void
    {
        $job = $this->store->job($jobId);
        if (
            $job['status'] !== 'running' ||
            !ProcessIdentity::alive($job) ||
            !in_array($member, array_column($job['plan']['requests'], 'workload'), true)
        ) {
            throw new RuntimeException('Cannot issue VM authorization');
        }
        Store::atomic($this->store->permissionPath($member), [
            'jobId' => $jobId,
            'workload' => $member,
            'expiresAt' => microtime(true) + self::AUTHORIZATION_SECONDS,
            'processIdentity' => $job['processIdentity'],
        ]);
        $this->store->debug->record('gate.permit_issued', [
            'jobId' => $jobId,
            'workloadId' => $member['id'],
        ]);
    }

    public function revoke(array $member): void
    {
        $path = $this->store->permissionPath($member);
        if (is_file($path)) {
            unlink($path);
            $this->store->debug->record('gate.permit_revoked', ['workloadId' => $member['id']]);
        }
    }

    public function prepare(array $member): void
    {
        if (!Config::groupsFor($this->store->config(), $member)) {
            return;
        }
        $path = $this->store->permissionPath($member);
        $lock = fopen($path . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('VM authorization is busy');
        }
        try {
            if (!is_file($path)) {
                throw new RuntimeException(
                    'Start this VM using the Unraid VMs or Dashboard Start button (Deadlock Guard group)',
                );
            }
            $permit = Store::read($path);
            unlink($path);
            $job = $this->store->job($permit['jobId'] ?? '');
            if (
                ($permit['workload'] ?? null) !== $member ||
                ($permit['expiresAt'] ?? 0) < microtime(true) ||
                $job['status'] !== 'running' ||
                !ProcessIdentity::alive($job) ||
                ($permit['processIdentity'] ?? null) !== $job['processIdentity']
            ) {
                throw new RuntimeException('Invalid or expired VM authorization');
            }
            $this->event($member, 'prepare');
            $this->store->debug->record('gate.authorized', [
                'workloadId' => $member['id'],
                'jobId' => $job['id'],
            ]);
        } catch (\Throwable $error) {
            $this->store->debug->record('gate.rejected', [
                'workloadId' => $member['id'],
                'errorType' => get_class($error),
                'reason' => 'permit_missing_or_invalid',
            ]);
            throw $error;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function event(array $member, string $phase): void
    {
        if (!in_array($phase, ['prepare', 'started', 'stopped', 'release'], true)) {
            return;
        }
        $path = $this->store->eventPath($member);
        $previous = is_file($path) ? Store::read($path) : [];
        Store::atomic($path, [
            'phase' => $phase,
            'at' => microtime(true),
            'release' => $phase === 'release' ? microtime(true) : $previous['release'] ?? 0,
        ]);
        $this->store->debug->record('gate.event', [
            'workloadId' => $member['id'],
            'phase' => $phase,
        ]);
        if ($phase === 'release') {
            $this->revoke($member);
        }
    }

    public static function vmFromXml(string $xml): array
    {
        if (
            strlen($xml) > self::MAX_XML_BYTES ||
            stripos($xml, '<!DOCTYPE') !== false ||
            stripos($xml, '<!ENTITY') !== false
        ) {
            throw new RuntimeException('Invalid domain XML');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $domain = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
            if ($domain === false) {
                throw new RuntimeException('Invalid domain XML');
            }
            return Config::member(['type' => 'vm', 'id' => (string) $domain->uuid]);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
