<?php

declare(strict_types=1);

// Floors are bound to the pinned Composer Package Standard §15.1.1.
$floors = ['8.4' => 80427, '8.5' => 80512];
$expectedMinor = $argv[1] ?? '';
$actualMinor = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

printf("PHP_VERSION=%s PHP_VERSION_ID=%d\n", PHP_VERSION, PHP_VERSION_ID);

if (! isset($floors[$expectedMinor])) {
    fwrite(STDERR, "Specify a supported PHP minor: 8.4 or 8.5.\n");
    exit(1);
}

if ($actualMinor !== $expectedMinor || preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/', PHP_VERSION) !== 1) {
    fwrite(STDERR, "Expected a Stable PHP $expectedMinor patch.\n");
    exit(1);
}

if (PHP_VERSION_ID < $floors[$expectedMinor]) {
    fwrite(STDERR, "PHP $expectedMinor is below its required security patch floor ({$floors[$expectedMinor]}).\n");
    exit(1);
}

printf("PHP %s security patch floor verification passed.\n", $expectedMinor);
