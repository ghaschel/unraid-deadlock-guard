<?php
// Copied beside a fixture's JSON settings so the supervisor can use its normal argv.
$fixture = json_decode(
    file_get_contents(__DIR__ . '/worker-fixture.json'),
    true,
    64,
    JSON_THROW_ON_ERROR,
);
require $fixture['bootstrap'];

use DeadlockGuard\Store;
use DeadlockGuard\ProcessIdentity;

$store = new Store($fixture['runDir'], $fixture['configFile']);
$id = $argv[2];
switch ($fixture['scenario']) {
    case 'crash':
        $store->updateJob($id, [
            'status' => 'running',
            'pid' => getmypid(),
            'processIdentity' => ProcessIdentity::of(getmypid()),
            'inFlight' => $fixture['inFlight'] ? ['action' => 'start'] : null,
        ]);
        $process = proc_open(
            ['/bin/kill', '-KILL', (string) getmypid()],
            [STDIN, STDOUT, STDERR],
            $pipes,
        );
        proc_close($process);
        break;
    case 'startup-failure':
        exit(9);
    case 'complete':
        $store->updateJob($id, ['status' => 'succeeded', 'phase' => 'Handoff complete']);
        break;
    case 'mark-launched':
        touch(__DIR__ . '/launched');
        break;
    default:
        throw new RuntimeException('Unknown worker fixture scenario');
}
