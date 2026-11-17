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

/** Returns the identity of regular file bytes in the package's Git tree. */
function artifactBlobId(string $contents): string
{
    return hash('sha1', 'blob ' . strlen($contents) . "\0" . $contents);
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
