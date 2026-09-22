<?php
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    $prefix = 'DeadlockGuard\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) require_once $file;
    }
});
