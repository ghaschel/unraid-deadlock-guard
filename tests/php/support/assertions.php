<?php
function test(string $name, callable $operation): void
{
    global $tests;
    $tests[$name] = $operation;
}
function eq(mixed $actual, mixed $expected): void
{
    if ($actual !== $expected) {
        throw new RuntimeException(
            'Expected ' . var_export($expected, true) . '; got ' . var_export($actual, true),
        );
    }
}
function ok(bool $value, string $why = 'assertion failed'): void
{
    if (!$value) {
        throw new RuntimeException($why);
    }
}
function raises(callable $operation, string $part): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        if (!str_contains($error->getMessage(), $part)) {
            throw $error;
        }
        return;
    }
    throw new RuntimeException('Expected error: ' . $part);
}
function tempdir(): string
{
    $directory = sys_get_temp_dir() . '/dg-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700, true);
    return $directory;
}
