<?php
// Linux container only. Emulates Slackware package extraction/removal, not an Unraid host.
if (PHP_SAPI !== 'cli' || !is_file('/.dockerenv')) {
    exit(1);
}
$base = '/usr/local/emhttp/plugins/deadlock-guard';
$flash = '/boot/config/plugins/deadlock-guard';
function check(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}
function execute(array $argv): void
{
    $p = proc_open($argv, [STDIN, STDOUT, STDERR], $pipes);
    if (proc_close($p) !== 0) {
        throw new RuntimeException('Command failed');
    }
}
@mkdir('/usr/local/sbin', 0755, true);
@mkdir('/usr/bin', 0755, true);
if (!is_file('/usr/bin/php')) {
    symlink(PHP_BINARY, '/usr/bin/php');
}
file_put_contents('/etc/unraid-version', "version=\"7.3.0\"\n");
@mkdir('/etc/libvirt/hooks/qemu.d', 0755, true);
file_put_contents('/etc/libvirt/hooks/qemu.d/other', 'foreign hook');
file_put_contents(
    '/usr/local/sbin/update_cron',
    "#!/bin/bash\necho 'Unexpected cron management during installation' >&2\nexit 1\n",
);
chmod('/usr/local/sbin/update_cron', 0755);
copy('/app/tests/fixtures/upgradepkg', '/usr/local/sbin/upgradepkg');
chmod('/usr/local/sbin/upgradepkg', 0755);
file_put_contents(
    '/usr/local/sbin/removepkg',
    "#!/bin/bash\nrm -rf /usr/local/emhttp/plugins/deadlock-guard\n",
);
chmod('/usr/local/sbin/removepkg', 0755);
$xml = simplexml_load_file('/app/dist/deadlock-guard.plg');
foreach ($xml->FILE as $file) {
    if (isset($file->URL)) {
        $path = (string) $file['Name'];
        @mkdir(dirname($path), 0700, true);
        copy('/app/dist/' . basename($path), $path);
        check(hash_file('sha256', $path) === (string) $file->SHA256, 'Package checksum mismatch');
    }
}
$run = function (string $method, bool $expectSuccess = true) use ($xml) {
    $output = '';
    foreach ($xml->FILE as $file) {
        if (((string) $file['Method'] ?: 'install') !== $method || !isset($file['Run'])) {
            continue;
        }
        $script = '/tmp/dg-manifest-step.sh';
        file_put_contents($script, (string) $file->INLINE);
        execute(['/bin/bash', '-n', $script]);
        $process = proc_open(
            ['/bin/bash', $script],
            [STDIN, ['pipe', 'w'], ['redirect', 1]],
            $pipes,
        );
        $part = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $output .= $part;
        echo $part;
        if (proc_close($process) !== 0) {
            check(!$expectSuccess, 'Manifest command failed');
            return $output;
        }
    }
    check(
        $expectSuccess,
        'Installer reported success after the package manager skipped the update',
    );
    return $output;
};
$expectedVersion = (string) $xml['version'];
@mkdir($flash, 0700, true);
file_put_contents($flash . '/foreign.cron', 'foreign cron');
$run('install');
check(is_file($base . '/lib/Coordinator.php'), 'Install missing payload');
check(is_executable('/etc/libvirt/hooks/qemu.d/99-deadlock-guard'), 'Missing executable hook');
check(!is_file($flash . '/deadlock-guard.cron'), 'Installation created a cron entry');
check(file_get_contents($flash . '/foreign.cron') === 'foreign cron', 'Foreign cron modified');
// Exercise the actual executable hook without a daemon or recursive libvirt calls.
require $base . '/lib/bootstrap.php';
$store = DeadlockGuard\Store::system();
$vm = ['type' => 'vm', 'id' => '11111111-1111-1111-1111-111111111111'];
$ct = ['type' => 'docker', 'id' => 'disposable'];
$store->saveConfig([
    'version' => 1,
    'groups' => [['id' => 'test', 'name' => 'Test', 'enabled' => true, 'members' => [$vm, $ct]]],
]);
$hook = function (string $operation, ?string $uuid = null) use ($vm) {
    $p = proc_open(
        [
            '/etc/libvirt/hooks/qemu.d/99-deadlock-guard',
            'Ignored Name',
            $operation,
            $operation === 'release' ? 'end' : 'begin',
            '-',
        ],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes,
    );
    fwrite($pipes[0], '<domain><uuid>' . ($uuid ?? $vm['id']) . '</uuid></domain>');
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    return proc_close($p);
};
check($hook('prepare') !== 0, 'Managed VM hook admitted unapproved start');
$job = (new DeadlockGuard\Jobs($store))->submit(
    [['workload' => $vm, 'action' => 'start']],
    'hook-smoke',
);
$store->updateJob($job['id'], [
    'status' => 'running',
    'pid' => getmypid(),
    'processIdentity' => DeadlockGuard\ProcessIdentity::of(getmypid()),
]);
(new DeadlockGuard\Gate($store))->issue($vm, $job['id']);
check($hook('prepare') === 0, 'Authorized hook rejected');
check($hook('prepare') !== 0, 'Hook reused authorization');
check($hook('release') === 0, 'Release hook failed');
check(
    DeadlockGuard\Store::read($store->eventPath($vm))['phase'] === 'release',
    'Missing release event',
);
$store->updateJob($job['id'], ['status' => 'succeeded']);
// Persistent hooks must protect grouped VMs yet leave other VMs usable if boot install fails.
rename($base, $base . '-offline');
check($hook('prepare') !== 0, 'Missing payload admitted grouped VM');
check(
    $hook('prepare', '22222222-2222-2222-2222-222222222222') === 0,
    'Missing payload blocked ungrouped VM',
);
rename($base . '-offline', $base);
file_put_contents('/etc/unraid-version', "version=\"7.4.0\"\n");
check($hook('prepare') !== 0, 'Unsupported boot admitted grouped VM');
check(
    $hook('prepare', '22222222-2222-2222-2222-222222222222') === 0,
    'Unsupported boot blocked ungrouped VM',
);
file_put_contents('/etc/unraid-version', "version=\"7.3.0\"\n");
// Exercise the installed launcher/supervisor/worker chain without an open browser.
// This container has no Docker/libvirt services, so the worker must fail visibly and exit.
$queued = (new DeadlockGuard\Jobs($store))->submit(
    [['workload' => $vm, 'action' => 'start']],
    'detached-smoke',
);
execute([$base . '/scripts/lifecycle', 'pending']);
execute([$base . '/scripts/lifecycle', 'pending']);
$deadline = microtime(true) + 5;
do {
    $result = $store->job($queued['id']);
    if (
        DeadlockGuard\Store::terminal($result) &&
        !DeadlockGuard\ProcessIdentity::alive($result['supervisor'] ?? null)
    ) {
        break;
    }
    usleep(20000);
} while (microtime(true) < $deadline);
check($result['status'] === 'failed', 'Detached worker did not report the unavailable services');
check(isset($result['supervisor']), 'Pending check did not use a supervisor');
check(
    !DeadlockGuard\ProcessIdentity::alive($result['supervisor']),
    'Supervisor remained after its handoff ended',
);
execute([$base . '/scripts/lifecycle', 'drain']);
check(is_file($store->runDir . '/draining.json'), 'Array stop did not block admission');
execute([$base . '/scripts/lifecycle', 'activate']);
check(!is_file($store->runDir . '/draining.json'), 'Array startup did not reactivate admission');
$config = file_get_contents($flash . '/config.json');
// The original date-only release sorts after same-day alpha-suffixed test builds.
file_put_contents($base . '/VERSION', "2026.09.22\n");
$run('install');
check(
    trim(file_get_contents($base . '/VERSION')) === $expectedVersion,
    'Upgrade skipped the original date-only installation',
);
check(file_get_contents($flash . '/config.json') === $config, 'Upgrade replaced configuration');
// A package tool may exit zero without replacing files. Do not announce success.
file_put_contents($base . '/VERSION', "2026.09.22\n");
putenv('DG_TEST_SKIP_PACKAGE=1');
try {
    $output = $run('install', false);
} finally {
    putenv('DG_TEST_SKIP_PACKAGE');
}
check(
    !str_contains($output, 'Deadlock Guard installed.'),
    'Skipped package printed installation success',
);
check(
    is_file($store->runDir . '/draining.json'),
    'Failed upgrade released the maintenance barrier',
);
$run('install');
check(
    trim(file_get_contents($base . '/VERSION')) === $expectedVersion,
    'Retry did not install the requested version',
);
check(
    !is_file($store->runDir . '/draining.json'),
    'Successful retry retained the maintenance barrier',
);
check(file_get_contents($flash . '/config.json') === $config, 'Retry replaced configuration');
$run('remove');
check(!is_dir($base), 'Removal left payload');
check(file_get_contents($flash . '/config.json') === $config, 'Removal lost configuration');
check(
    file_get_contents('/etc/libvirt/hooks/qemu.d/other') === 'foreign hook',
    'Foreign hook modified',
);
check(!is_file($flash . '/installed.json'), 'Stale mounted-image hook still enabled');
check(!is_file($flash . '/deadlock-guard.cron'), 'Cron retained after removal');
echo "PASS disposable Linux manifest install/update/remove and foreign-hook/config preservation\n";
