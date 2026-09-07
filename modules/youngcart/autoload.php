<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'GnuCms\\Modules\\YoungCart\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    if (!preg_match('/^[A-Za-z][A-Za-z0-9]*(\\\\[A-Za-z][A-Za-z0-9]*)*$/D', $relative)) return;
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) require_once $file;
});
