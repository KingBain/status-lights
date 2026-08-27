<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LegacySuiteTest extends TestCase
{
    public function testExistingUnitSuite(): void
    {
        require dirname(__DIR__) . '/run.php';

        $this->addToAssertionCount(1);
    }
}
