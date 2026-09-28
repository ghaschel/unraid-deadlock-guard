<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/lib/bootstrap.php';

try {
    $member = DeadlockGuard\Config::member(['type' => 'vm', 'id' => $argv[1] ?? '']);
    $wrapper = dirname(__DIR__, 2) . '/dynamix.vm.manager/include/libvirt.php';
    if (!is_readable($wrapper)) {
        throw new RuntimeException('Unraid VM manager is unavailable');
    }
    require_once $wrapper;
    $libvirt = new Libvirt('qemu:///system');
    if (!$libvirt->enabled()) {
        throw new RuntimeException('VM service is unavailable: ' . $libvirt->get_last_error());
    }
    // Keep the domain handle so a renamed or reused VM name cannot change the target.
    $domain = $libvirt->domain_get_domain_by_uuid($member['id']);
    if (!$domain) {
        throw new RuntimeException('VM lookup failed: ' . $libvirt->get_last_error());
    }
    if (!$libvirt->domain_shutdown($domain)) {
        throw new RuntimeException('VM shutdown request failed: ' . $libvirt->get_last_error());
    }
    // Acceptance is not shutdown completion. The coordinator checks state and release/end.
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
