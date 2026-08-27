<?php

declare(strict_types=1);

test('the cPanel deployment includes the shared runtime', function (): void {
    // Arrange
    $manifest = file_get_contents(__DIR__ . '/../../.cpanel.yml');

    if (!is_string($manifest)) {
        throw new RuntimeException('Unable to read the cPanel deployment manifest.');
    }

    // Act
    $deploysSharedRuntime = str_contains(
        $manifest,
        '- /bin/cp generator/core.php $DEPLOYPATH/core.php',
    );

    // Assert
    expect($deploysSharedRuntime)->toBeTrue();
});
