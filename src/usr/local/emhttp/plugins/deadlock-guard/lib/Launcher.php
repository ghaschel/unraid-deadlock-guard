<?php
declare(strict_types=1);
namespace DeadlockGuard;

final class Launcher
{
    public static function worker(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new \RuntimeException('Invalid job ID');
        }
        // The shell program is constant. Every dynamic value is a separate positional argument.
        $argv = [
            '/bin/bash',
            '-c',
            '"$@" </dev/null >/dev/null 2>&1 &',
            'deadlock-guard',
            '/usr/bin/setsid',
            '/usr/bin/php',
            '-d',
            'auto_prepend_file=',
            dirname(__DIR__) . '/scripts/cli.php',
            'supervise',
            $id,
        ];
        (new Runner())->run($argv, timeout: 5);
    }
}
