<?php
declare(strict_types=1);
namespace DeadlockGuard;
interface Platform
{
    /** status: stopped|running|paused|suspended|restarting|unknown; release: last completed release timestamp */
    public function inspect(array $member): array;
    public function stop(array $member): void;
    public function forceStop(array $member): void;
    public function act(array $member, string $action): void;
    public function now(): float;
    public function pause(): void;
}
