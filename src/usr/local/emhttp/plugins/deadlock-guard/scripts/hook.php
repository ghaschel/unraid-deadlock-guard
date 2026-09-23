<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
use DeadlockGuard\Gate;
use DeadlockGuard\Store;
use DeadlockGuard\Config;
// Do not include coordinator/platform code: this path must never make recursive libvirt calls.
$operation = $argv[2] ?? '';
$stage = $argv[3] ?? '';
if (
    !in_array(
        $operation,
        ['prepare', 'started', 'stopped', 'release', 'migrate', 'restore', 'attach'],
        true,
    )
) {
    exit(0);
}
try {
    $xml = stream_get_contents(STDIN, Gate::MAX_XML_BYTES + 1);
    $member = Gate::vmFromXml($xml);
    $store = Store::system();
    $gate = new Gate($store);
    if ($operation === 'prepare' && $stage === 'begin') {
        $gate->prepare($member);
    } elseif (
        in_array($operation, ['migrate', 'restore', 'attach'], true) &&
        Config::groupsFor($store->config(), $member)
    ) {
        throw new RuntimeException(
            'Managed VM migration, restore and external attach are not supported; use an ordinary Start through Deadlock Guard.',
        );
    } elseif (in_array($operation, ['started', 'stopped', 'release'], true)) {
        $gate->event($member, $operation);
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Deadlock Guard: ' . $error->getMessage() . "\n");
    exit(1);
}
