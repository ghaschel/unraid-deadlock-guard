<?php
declare(strict_types=1);
namespace DeadlockGuard;

use RuntimeException;

final class Runner implements CommandRunner
{
    private const MAX_OUTPUT_BYTES = 4 * 1024 * 1024;

    public function run(
        array $argv,
        float $timeout = 10,
        ?string $input = null,
        bool $mutation = false,
    ): string {
        if (!$argv || $timeout <= 0) {
            throw new RuntimeException('Invalid command');
        }
        foreach ($argv as $argument) {
            if (!is_string($argument) || str_contains($argument, "\0")) {
                throw new RuntimeException('Invalid command argument');
            }
        }
        $environment = [
            'PATH' => '/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin',
            'LC_ALL' => 'C',
            'LANG' => 'C',
        ];
        $process = proc_open(
            $argv,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            null,
            $environment,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start command');
        }
        if ($input !== null) {
            fwrite($pipes[0], $input);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = hrtime(true) / 1e9 + $timeout;
        $status = null;
        try {
            while (true) {
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                if (strlen($stdout) + strlen($stderr) > self::MAX_OUTPUT_BYTES) {
                    throw $mutation
                        ? new UncertainOperation(
                            'Command output exceeds limit; operation uncertain',
                        )
                        : new RuntimeException('Command output exceeds limit');
                }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                if (hrtime(true) / 1e9 >= $deadline) {
                    $message =
                        basename($argv[0]) .
                        ' timed out; ' .
                        ($mutation ? 'operation outcome is uncertain' : 'service is unavailable');
                    if ($mutation) {
                        throw new UncertainOperation($message);
                    }
                    throw new RuntimeException($message);
                }
                usleep(20000);
            }
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            if ($status['exitcode'] !== 0) {
                $message = basename($argv[0]) . ': ' . trim(substr($stderr ?: $stdout, 0, 2000));
                if ($mutation) {
                    throw new UncertainOperation($message . '; operation outcome is uncertain');
                }
                throw new RuntimeException($message);
            }
            return $stdout;
        } finally {
            if ($status['running'] ?? true) {
                proc_terminate($process, 15);
                usleep(50000);
                $finalStatus = proc_get_status($process);
                if ($finalStatus['running']) {
                    proc_terminate($process, 9);
                }
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }
}
