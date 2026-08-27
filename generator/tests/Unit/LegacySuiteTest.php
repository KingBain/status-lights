<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LegacySuiteTest extends TestCase
{
    public function testExistingUnitSuite(): void
    {
        $tests = require dirname(__DIR__) . '/run.php';

        foreach ($tests as [$name, $test]) {
            try {
                $test();
                $this->addToAssertionCount(1);
            } catch (Throwable $exception) {
                self::fail(sprintf('%s: %s', $name, $exception->getMessage()));
            }
        }
    }
}
