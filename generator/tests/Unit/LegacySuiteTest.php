<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LegacySuiteTest extends TestCase
{
    public function testExistingUnitSuite(): void
    {
        require dirname(__DIR__) . '/run.php';

        $request = status_lights_parse_request('/github/owner/repository/workflow.yml.svg');
        self::assertSame('owner', $request->owner);
    }
}
