<?php
declare(strict_types=1);
// Pure-PHP unit tests: load classes without WordPress.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Chatreghermez\\Commerce\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $rel  = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = dirname(__DIR__) . '/src/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});
