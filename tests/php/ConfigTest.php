<?php
use DeadlockGuard\Config;
test('configuration normalizes defaults and permits overlapping groups', function () {
    $vm = member('vm', '11111111-1111-1111-1111-111111111111');
    $configuration = config([
        group('gpu', [$vm, member('docker', 'FileFlows')]),
        group('usb', [$vm, member('docker', 'HA')]),
    ]);
    eq($configuration['groups'][0]['vmTimeout'], 120);
    eq($configuration['groups'][0]['containerTimeout'], 30);
    eq($configuration['groups'][0]['forceVm'], false);
    eq($configuration['groups'][0]['forceContainer'], false);
    eq(count(Config::groupsFor($configuration, $vm)), 2);
});

test('configuration rejects malformed members and policies', function () {
    foreach (
        [
            member('shell', 'x'),
            member('vm', 'not-a-uuid'),
            member('docker', 'a;touch /tmp/evil'),
            member('docker', '-x'),
        ]
        as $bad
    ) {
        raises(fn() => config([group('x', [$bad, member('docker', 'ok')])]), 'Invalid');
    }
    raises(
        fn() => config([group('x', [member('docker', 'a'), member('docker', 'a')])]),
        'Duplicate',
    );
    raises(
        fn() => config([
            group('x', [member('docker', 'a'), member('docker', 'b')], ['vmTimeout' => 0]),
        ]),
        'timeout',
    );
    raises(fn() => Config::validate(['version' => 2, 'groups' => []]), 'version');
});

test('batch conflict validation rejects same group before any execution', function () {
    $a = member('docker', 'a');
    $b = member('docker', 'b');
    $configuration = config([group('gpu', [$a, $b])]);
    raises(
        fn() => Config::plan($configuration, [
            ['workload' => $a, 'action' => 'start'],
            ['workload' => $b, 'action' => 'start'],
        ]),
        'exclusive group',
    );
    $plan = Config::plan($configuration, [['workload' => $a, 'action' => 'start']]);
    eq($plan['groups'], ['gpu']);
    eq($plan['conflicts'], [$b]);
});

test('existing groups default both WebUI and API handoffs on', function () {
    $legacy = config([group('gpu', [member('docker', 'a'), member('docker', 'b')])]);
    eq($legacy['groups'][0]['webui'] ?? null, true);
    eq($legacy['groups'][0]['api'] ?? null, true);
});

test('groups require a boolean source selection and preserve each valid combination', function () {
    $members = [member('docker', 'a'), member('docker', 'b')];
    foreach ([[true, false], [false, true], [true, true]] as [$webui, $api]) {
        $saved = config([group('gpu', $members, ['webui' => $webui, 'api' => $api])]);
        eq($saved['groups'][0]['webui'] ?? null, $webui);
        eq($saved['groups'][0]['api'] ?? null, $api);
    }
    foreach ([true, false] as $enabled) {
        raises(
            fn() => config([
                group('gpu', $members, ['enabled' => $enabled, 'webui' => false, 'api' => false]),
            ]),
            'Select WebUI, API, or both',
        );
    }
    foreach (['webui', 'api'] as $field) {
        foreach (['false', 0, 1, null, []] as $invalid) {
            raises(
                fn() => config([group('gpu', $members, [$field => $invalid])]),
                'Invalid handoff source',
            );
        }
    }
});

test('saving a group without a source preserves the saved configuration', function () {
    $dir = tempdir();
    $store = new DeadlockGuard\Store($dir . '/run', $dir . '/config.json');
    $initial = config([group('gpu', [member('docker', 'a'), member('docker', 'b')])]);
    $store->saveConfig($initial);
    $revision = $store->revision();
    $invalid = $initial;
    $invalid['groups'][0]['webui'] = false;
    $invalid['groups'][0]['api'] = false;
    raises(fn() => $store->saveConfig($invalid, $revision), 'Select WebUI, API, or both');
    eq($store->revision(), $revision);
    eq($store->config(), $initial);
});
