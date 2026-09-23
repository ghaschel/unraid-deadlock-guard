<?php
declare(strict_types=1);
namespace DeadlockGuard;
interface CommandRunner
{
    public function run(
        array $argv,
        float $timeout = 10,
        ?string $input = null,
        bool $mutation = false,
    ): string;
}
