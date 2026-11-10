<?php
declare(strict_types=1);
/**
 * Fallback runner for environments where Composer/PHPUnit cannot be installed.
 * Usage: php tests/run-without-phpunit.php
 * Real CI and the dev machine should use `composer test` (PHPUnit 10).
 */
namespace PHPUnit\Framework {
    if (!class_exists(TestCase::class)) {
        class AssertionFailed extends \Exception {}
        abstract class TestCase
        {
            private ?string $expected = null;
            public function expectException(string $c): void { $this->expected = $c; }
            public function expectedException(): ?string { return $this->expected; }
            protected function fail(string $m): never { throw new AssertionFailed($m); }
            public function assertSame($e, $a, string $m = ''): void { if ($e !== $a) $this->fail($m ?: 'expected ' . var_export($e, true) . ' got ' . var_export($a, true)); }
            public function assertTrue($a, string $m = ''): void { if ($a !== true) $this->fail($m ?: 'expected true'); }
            public function assertFalse($a, string $m = ''): void { if ($a !== false) $this->fail($m ?: 'expected false'); }
            public function assertNull($a, string $m = ''): void { if ($a !== null) $this->fail($m ?: 'expected null'); }
            public function assertCount(int $n, $c, string $m = ''): void { if (count($c) !== $n) $this->fail($m ?: "expected count $n got " . count($c)); }
            public function assertEmpty($a, string $m = ''): void { if (!empty($a)) $this->fail($m ?: 'expected empty'); }
            public function assertArrayHasKey($k, array $a, string $m = ''): void { if (!array_key_exists($k, $a)) $this->fail($m ?: "missing key $k"); }
            public function assertArrayNotHasKey($k, array $a, string $m = ''): void { if (array_key_exists($k, $a)) $this->fail($m ?: "unexpected key $k"); }
            public function assertContains($n, array $h, string $m = ''): void { if (!in_array($n, $h, true)) $this->fail($m ?: "missing {$n}"); }
            public function assertNotContains($n, array $h, string $m = ''): void { if (in_array($n, $h, true)) $this->fail($m ?: "unexpected {$n}"); }
        }
    }
}
namespace {
    require __DIR__ . '/bootstrap.php';
    $pass = 0; $failed = [];
    foreach (glob(__DIR__ . '/*Test.php') as $file) {
        require_once $file;
        $class = 'Chatreghermez\\Commerce\\Tests\\' . basename($file, '.php');
        foreach (get_class_methods($class) as $method) {
            if (strncmp($method, 'test', 4) !== 0) continue;
            $t = new $class();
            try {
                $t->$method();
                if ($t->expectedException()) { $failed[] = "$class::$method (expected exception not thrown)"; continue; }
                $pass++;
            } catch (\Throwable $e) {
                if ($t->expectedException() && $e instanceof ($t->expectedException())) { $pass++; continue; }
                $failed[] = "$class::$method: " . $e->getMessage();
            }
        }
    }
    echo "passed: $pass, failed: " . count($failed) . "\n";
    foreach ($failed as $f) echo " - $f\n";
    exit($failed ? 1 : 0);
}
