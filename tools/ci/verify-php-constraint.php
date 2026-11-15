<?php

declare(strict_types=1);

use Composer\Semver\Semver;

$root = dirname(__DIR__, 2);
$autoload = $root . '/vendor/autoload.php';
if (! is_file($autoload)) {
    fwrite(STDERR, "Install development dependencies before verifying PHP constraint boundaries.\n");
    exit(1);
}
require $autoload;

$manifestPath = $argv[1] ?? $root . '/composer.json';
$contents = file_get_contents($manifestPath);
if ($contents === false) {
    fwrite(STDERR, "Unable to read the Composer manifest.\n");
    exit(1);
}

$manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
$constraint = $manifest['require']['php'] ?? null;
if (! is_string($constraint) || $constraint === '') {
    fwrite(STDERR, "The Composer manifest must declare require.php.\n");
    exit(1);
}

// These are Composer-only probes, including the hypothetical forward PHP 8.6 minor.
$expectations = [
    '8.3.99' => false,
    '8.4.0' => false,
    '8.4.26' => false,
    '8.4.27' => true,
    '8.4.28' => true,
    '8.5.0' => false,
    '8.5.11' => false,
    '8.5.12' => true,
    '8.5.13' => true,
    '8.6.0' => true,
    '9.0.0' => false,
];
$passed = true;

foreach ($expectations as $version => $expected) {
    $allowed = Semver::satisfies($version, $constraint);
    $matches = $allowed === $expected;
    printf("PHP %s: %s (expected %s) %s\n", $version, $allowed ? 'accepted' : 'rejected', $expected ? 'accepted' : 'rejected', $matches ? 'PASS' : 'FAIL');
    $passed = $passed && $matches;
}

if (($manifest['config']['platform']['php'] ?? null) !== '8.4.27') {
    fwrite(STDERR, "config.platform.php must match the lowest eligible patch (8.4.27).\n");
    $passed = false;
}

exit($passed ? 0 : 1);
