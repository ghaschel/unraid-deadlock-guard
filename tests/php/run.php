<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2) . '/src/usr/local/emhttp/plugins/deadlock-guard';
$tests = [];
require $root . '/lib/bootstrap.php';
require __DIR__ . '/support/assertions.php';
require __DIR__ . '/support/builders.php';
require __DIR__ . '/support/SimulatedPlatform.php';
require __DIR__ . '/support/scenarios.php';
require __DIR__ . '/support/worker.php';

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require $file;
}
$failed = 0;
foreach ($tests as $name => $fn) {
    try {
        $fn();
        echo "PASS $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL $name: " . $e->getMessage() . "\n";
    }
}
printf("%d tests, %d failures\n", count($tests), $failed);
exit($failed ? 1 : 0);
