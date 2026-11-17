<?php

declare(strict_types=1);

/** Reads a verification input or fails with its origin. */
function readArtifactInput(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Unable to read verification input: ' . $path);
    }

    return $contents;
}

/**
 * Collects the unfiltered Git blobs from the consumer directories and root documents.
 *
 * @return array<string, string> Git blob identity by relative path
 */
function requiredBlobs(string $candidateTree): array
{
    $rootFiles = [
        'CHANGELOG.md', 'CODE_OF_CONDUCT.md', 'CONTRIBUTING.md', 'LICENSE', 'README.md', 'README_AR.md',
        'SECURITY.md', 'SLUG_PACKAGE_REFERENCE.md', 'composer.json', 'llms.txt',
    ];
    $directories = ['src/', 'schema/', 'docs/guides/', 'examples/'];
    $blobs = [];
    foreach (explode("\0", rtrim(readArtifactInput($candidateTree), "\0")) as $line) {
        $fields = explode("\t", $line, 2);
        if (count($fields) !== 2) {
            throw new RuntimeException('Unexpected candidate Git tree entry.');
        }
        [$metadata, $path] = $fields;
        if (! in_array($path, $rootFiles, true) && ! array_any($directories, static fn(string $prefix): bool => str_starts_with($path, $prefix))) {
            continue;
        }
        if (preg_match('/\A100(?:644|755) blob ([0-9a-f]{40})\z/', $metadata, $match) !== 1) {
            throw new RuntimeException('Candidate package path is not a regular tracked file: ' . $path);
        }
        $blobs[$path] = $match[1];
    }
    foreach ($rootFiles as $path) {
        if (! isset($blobs[$path])) {
            throw new RuntimeException('The candidate Git tree lacks the required root file: ' . $path);
        }
    }
    foreach ($directories as $prefix) {
        if (! array_any(array_keys($blobs), static fn(string $path): bool => str_starts_with($path, $prefix))) {
            throw new RuntimeException('The candidate Git tree lacks files under ' . $prefix);
        }
    }
    ksort($blobs, SORT_STRING);

    return $blobs;
}

/** Rejects escaped archive paths and well-known development and credential filenames. */
function assertSafeArchivePath(string $path): void
{
    $segments = explode('/', $path);
    foreach ($segments as $segment) {
        if (in_array($segment, [
            '', '.', '..', '.agents', '.git', '.github', '.idea', '.fleet', '.vscode', '.DS_Store',
            '.php-cs-fixer.cache', '.phpunit.cache', '.phpunit.result.cache', 'auth.json', 'composer.lock', 'vendor',
        ], true) || preg_match('/\A\.env(?:\.|\z)|\.(?:iml|pem|key|p12|pfx)\z|[\x00-\x1f\\\\]/i', $segment) === 1) {
            throw new RuntimeException('Forbidden archive path: ' . $path);
        }
    }
}

/**
 * Reads the actual archive payload without extracting entries to the filesystem.
 *
 * @return array<string, string> SHA-256 by relative path
 */
function archiveChecksums(PharData $archive): array
{
    $checksums = [];
    $prefix = 'phar://' . $archive->getPath() . '/';
    foreach (new RecursiveIteratorIterator($archive) as $entry) {
        $absolute = $entry->getPathname();
        if (! str_starts_with($absolute, $prefix) || ! $entry->isFile() || $entry->isLink()) {
            throw new RuntimeException('Archive contains an invalid file entry.');
        }
        $path = substr($absolute, strlen($prefix));
        assertSafeArchivePath($path);
        $checksums[$path] = hash('sha256', readArtifactInput($absolute));
    }
    ksort($checksums, SORT_STRING);

    return $checksums;
}

/** Reads an actual archived presentation file. */
function artifactDocument(PharData $artifact, string $path): string
{
    return readArtifactInput('phar://' . $artifact->getPath() . '/' . $path);
}

/** Verifies artifact-target claims that can be determined mechanically. */
function verifyReleaseMarkers(PharData $artifact, string $target): void
{
    $composer = json_decode(artifactDocument($artifact, 'composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $dependency = $composer['require']['maatify/php-persistence'] ?? null;
    if (($composer['name'] ?? null) !== 'maatify/php-slug' || ($composer['minimum-stability'] ?? null) !== 'stable'
        || ($composer['prefer-stable'] ?? null) !== true || ! is_string($dependency)) {
        throw new RuntimeException('Archived Composer identity or stable dependency-resolution settings are invalid.');
    }
    $number = '(?:0|[1-9][0-9]*)';
    $version = $number . '\\.' . $number . '\\.' . $number . '-rc\\.' . $number;
    if (preg_match('/\A=?(' . $version . ')\z/', $dependency, $match) !== 1
        && preg_match('/\A>=(' . $version . ')\h+<=\1\z/', $dependency, $match) !== 1) {
        throw new RuntimeException('The archived Persistence trial constraint must pin exactly one RC.');
    }

    $install = 'composer require maatify/php-slug:' . $target . '@RC maatify/php-persistence:' . $match[1] . '@RC';
    $readme = artifactDocument($artifact, 'README.md');
    foreach (['README.md' => $readme, 'README_AR.md' => artifactDocument($artifact, 'README_AR.md')] as $name => $contents) {
        if (! in_array($install, preg_split('/\R/', $contents) ?: [], true)) {
            throw new RuntimeException($name . ' lacks the exact package-scoped installation command for the target and its Persistence dependency.');
        }
    }
    $badgeTarget = str_replace('-', '--', $target);
    if (! str_contains($readme, '**Release target:** `' . $target . '`')
        || ! str_contains($readme, '`' . $target . '` is unpublished')
        || ! str_contains($readme, '/badge/Version-' . $badgeTarget . '-')
        || ! str_contains($readme, '/badge/Install%20after%20publication-' . $badgeTarget . '%40RC-')) {
        throw new RuntimeException('README.md lacks the exact unpublished release target and badges.');
    }

    $changelog = artifactDocument($artifact, 'CHANGELOG.md');
    preg_match_all('/^##\h+([^\r\n]*\S)\h*\r?$/m', $changelog, $headings);
    $firstRelease = ($headings[1][0] ?? null) === '[Unreleased]' ? 1 : 0;
    $heading = $headings[1][$firstRelease] ?? null;
    if ($heading !== $target && $heading !== '[' . $target . ']') {
        throw new RuntimeException('CHANGELOG.md must put the exact undated target in its first release section.');
    }
    $security = artifactDocument($artifact, 'SECURITY.md');
    if (! str_contains($security, 'This artifact targets `' . $target . '` as a Pre-Stable Release Candidate.')
        || ! str_contains($security, '| `' . $target . '` pre-release (artifact target) | No |')) {
        throw new RuntimeException('SECURITY.md lacks the exact release target and unsupported pre-release row.');
    }
}

if ($argc !== 7) {
    fwrite(STDERR, "Use the shell driver to run Release Artifact Verification.\n");
    exit(2);
}

try {
    [, $checkout, $candidateTree, $target, $gitTar, $composerZip, $populatedZip] = $argv;
    $expected = [];
    foreach (requiredBlobs($candidateTree) as $path => $blob) {
        assertSafeArchivePath($path);
        $sourcePath = $checkout . '/' . $path;
        if (! is_file($sourcePath) || is_link($sourcePath)) {
            throw new RuntimeException('The exact candidate checkout lacks a regular required file: ' . $path);
        }
        $contents = readArtifactInput($sourcePath);
        if (hash('sha1', 'blob ' . strlen($contents) . "\0" . $contents) !== $blob) {
            throw new RuntimeException('Checkout bytes do not match the exact candidate Git blob: ' . $path);
        }
        $expected[$path] = hash('sha256', $contents);
    }

    foreach (['GIT_TAR' => $gitTar, 'COMPOSER_ZIP' => $composerZip, 'POPULATED_COMPOSER_ZIP' => $populatedZip] as $kind => $path) {
        $archive = new PharData($path);
        $actual = archiveChecksums($archive);
        if ($actual !== $expected) {
            $missing = array_keys(array_diff_key($expected, $actual));
            $extra = array_keys(array_diff_key($actual, $expected));
            $modified = array_keys(array_diff_assoc(array_intersect_key($actual, $expected), $expected));
            throw new RuntimeException(sprintf('%s payload differs from the exact candidate tree. Missing: [%s]. Unexpected: [%s]. Modified: [%s].', $kind, implode(', ', $missing), implode(', ', $extra), implode(', ', $modified)));
        }
        verifyReleaseMarkers($archive, $target);
        printf("RAV_%s_SHA256=%s\n", $kind, hash_file('sha256', $path));
    }

    printf("RAV_PAYLOAD_SHA256=%s\n", hash('sha256', json_encode($expected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
    printf("RAV_ARTIFACT_FILE_COUNT=%d\n", count($expected));
    foreach (['RUNTIME' => 'src/', 'SCHEMA' => 'schema/'] as $kind => $prefix) {
        printf("RAV_%s_FILE_COUNT=%d\n", $kind, count(array_filter(array_keys($expected), static fn(string $path): bool => str_starts_with($path, $prefix))));
    }
    echo "RAV_RELEASE_MARKERS=PASS\nRAV=PASS\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Release Artifact Verification failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
