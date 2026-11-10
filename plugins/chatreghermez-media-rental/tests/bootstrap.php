<?php
declare(strict_types=1);
// Pure-PHP unit tests: load classes without WordPress. The commerce core is loaded too (Money etc.).
foreach (['Chatreghermez\\MediaRental\\' => __DIR__ . '/../src/', 'Chatreghermez\\Commerce\\' => __DIR__ . '/../../chatreghermez-commerce/src/'] as $prefix => $dir) {
    spl_autoload_register(static function (string $class) use ($prefix, $dir): void {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}
