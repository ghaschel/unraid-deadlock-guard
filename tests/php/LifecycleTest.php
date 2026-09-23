<?php
use DeadlockGuard\Lifecycle;
use DeadlockGuard\Store;
test(
    'install preserves foreign hooks and configuration; newly live hook needs activation',
    function () {
        $directory = tempdir();
        $store = new Store($directory . '/run', $directory . '/boot/config.json');
        $store->saveConfig(config([]));
        $lifecycle = new Lifecycle($store, $directory, fn() => 'daemon-1');
        mkdir($directory . '/etc/libvirt/hooks/qemu.d', 0755, true);
        file_put_contents($directory . '/etc/libvirt/hooks/qemu.d/other', '# foreign');
        $lifecycle->install();
        eq(file_get_contents($directory . '/etc/libvirt/hooks/qemu.d/other'), '# foreign');
        eq($store->config(), config([]));
        ok(!$lifecycle->health()['ready']);
        $restartedLifecycle = new Lifecycle($store, $directory, fn() => 'daemon-2');
        $restartedLifecycle->install();
        ok($restartedLifecycle->health()['ready']);
        $restartedLifecycle->remove();
        ok(is_file($store->configFile));
        ok(is_file($directory . '/etc/libvirt/hooks/qemu.d/other'));
        ok(!is_file($directory . '/etc/libvirt/hooks/qemu.d/99-deadlock-guard'));
    },
);

test('refused removal and upgrade leave active handoffs and admission intact', function () {
    [$store, $platform, $job, $a, $b] = scenario('docker', 'vm');
    $lifecycle = new Lifecycle($store, tempdir(), fn() => null);
    raises(fn() => $lifecycle->remove(), 'Active');
    ok(!is_file($store->runDir . '/draining.json'), 'Refused removal stranded the active worker');
    raises(fn() => $lifecycle->prepareUpgrade(), 'Active');
    ok(!is_file($store->runDir . '/draining.json'), 'Refused update stranded the active worker');
    (new DeadlockGuard\Coordinator($store, $platform))->run($job['id']);
    eq($store->job($job['id'])['status'], 'succeeded');
});

test('checks cannot resurrect removal or rewrite persistent install intent', function () {
    $directory = tempdir();
    $store = new Store($directory . '/run', $directory . '/boot/config');
    $lifecycle = new Lifecycle($store, $directory, fn() => null);
    $lifecycle->install();
    $marker = dirname($store->configFile) . '/installed.json';
    touch($marker, 123456789);
    $lifecycle->check();
    clearstatcache();
    eq(filemtime($marker), 123456789);
    $lifecycle->remove();
    $lifecycle->check();
    ok(!is_file($marker), 'Stale check re-enabled removed plugin');
    ok(!is_file($directory . '/etc/libvirt/hooks/qemu.d/99-deadlock-guard'));
});

test(
    'aborted updates recover admission only when updater is gone and payload is unchanged',
    function () {
        $directory = tempdir();
        $store = new Store($directory . '/run', $directory . '/boot/config');
        mkdir($directory . '/payload');
        file_put_contents($directory . '/payload/code', 'old');
        $lifecycle = new Lifecycle($store, $directory, fn() => null, $directory . '/payload');
        $lifecycle->install();
        $lifecycle->prepareUpgrade(getmypid());
        $lifecycle->check();
        ok(is_file($store->runDir . '/draining.json'), 'Live updater barrier lost');
        $record = Store::read($store->runDir . '/draining.json');
        $record['owner'] = ['pid' => 99999999, 'processIdentity' => 'gone'];
        Store::atomic($store->runDir . '/draining.json', $record);
        $lifecycle->check();
        ok(
            !is_file($store->runDir . '/draining.json'),
            'Unchanged interrupted update stranded admission',
        );
        $lifecycle->prepareUpgrade(getmypid());
        $record = Store::read($store->runDir . '/draining.json');
        $record['owner'] = ['pid' => 99999999, 'processIdentity' => 'gone'];
        Store::atomic($store->runDir . '/draining.json', $record);
        file_put_contents($directory . '/payload/code', 'partial new payload');
        $lifecycle->check();
        ok(
            is_file($store->runDir . '/draining.json'),
            'Partial package replacement was treated as safe',
        );
    },
);

test(
    'array events preserve update barriers and installation preserves an ongoing array stop',
    function () {
        foreach ([true, false] as $arrayStartedBeforeInstall) {
            $directory = tempdir();
            $store = new Store($directory . '/run', $directory . '/boot/config');
            $lifecycle = new Lifecycle($store, $directory, fn() => null);
            $lifecycle->install();
            $lifecycle->prepareUpgrade(getmypid());
            $lifecycle->drain();
            eq(Store::read($store->runDir . '/draining.json')['reason'], 'maintenance');
            if ($arrayStartedBeforeInstall) {
                $lifecycle->activate();
            }
            $lifecycle->check();
            eq(Store::read($store->runDir . '/draining.json')['reason'], 'maintenance');
            $lifecycle->install();
            eq(is_file($store->runDir . '/draining.json'), !$arrayStartedBeforeInstall);
            if (!$arrayStartedBeforeInstall) {
                eq(Store::read($store->runDir . '/draining.json')['reason'], 'array');
                $lifecycle->activate();
                ok(!is_file($store->runDir . '/draining.json'));
            }
        }
    },
);
