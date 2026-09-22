<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2) . '/source/usr/local/emhttp/plugins/deadlock-guard';
$tests = [];
function test(string $name, callable $fn): void { global $tests; $tests[$name] = $fn; }
function eq(mixed $a, mixed $b): void { if ($a !== $b) throw new RuntimeException('Expected '.var_export($b,true).'; got '.var_export($a,true)); }
function ok(bool $value, string $why = 'assertion failed'): void { if (!$value) throw new RuntimeException($why); }
function raises(callable $fn, string $part): void { try { $fn(); } catch (Throwable $e) { if (!str_contains($e->getMessage(),$part)) throw $e; return; } throw new RuntimeException('Expected error: '.$part); }
function tempdir(): string { $p=sys_get_temp_dir().'/dg-'.bin2hex(random_bytes(8)); mkdir($p,0700,true); return $p; }
if (is_file($root.'/lib/bootstrap.php')) require $root.'/lib/bootstrap.php';
foreach (glob(__DIR__.'/*Test.php') as $file) require $file;
$failed = 0;
foreach ($tests as $name=>$fn) { try { $fn(); echo "PASS $name\n"; } catch (Throwable $e) { $failed++; echo "FAIL $name: ".$e->getMessage()."\n"; } }
printf("%d tests, %d failures\n", count($tests), $failed);
exit($failed ? 1 : 0);
