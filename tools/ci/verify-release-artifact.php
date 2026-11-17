<?php

declare(strict_types=1);

require __DIR__ . '/release-artifact-markdown.php';
require __DIR__ . '/release-artifact-files.php';

const RELEASE_READMES = [
    'README.md' => ['Publication State', 'Installation', 'release\h+target'],
    'README_AR.md' => ['حالة النشر', 'التثبيت', 'الإصدار\h+المستهدف'],
];

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
    if (array_key_exists('version', $composer)) {
        throw new RuntimeException('Archived composer.json must not declare a version key.');
    }
    $number = '(?:0|[1-9][0-9]*)';
    $version = $number . '\\.' . $number . '\\.' . $number . '-rc\\.' . $number;
    if (preg_match('/\A=?(' . $version . ')\z/', $dependency, $match) !== 1
        && preg_match('/\A>=(' . $version . ')\h+<=\1\z/', $dependency, $match) !== 1) {
        throw new RuntimeException('The archived Persistence trial constraint must pin exactly one RC.');
    }

    $install = 'composer require maatify/php-slug:' . $target . '@RC maatify/php-persistence:' . $match[1] . '@RC';
    foreach (RELEASE_READMES as $name => [$publicationTitle, $installationTitle, $label]) {
        $document = parseReleaseMarkdown(artifactDocument($artifact, $name), $name);
        $publication = markdownSections($document, $publicationTitle);
        if (array_any($publication, markdownHasRawHtmlBlocks(...))) {
            throw new RuntimeException($name . ' publication-state section must use Markdown. Raw HTML blocks are not allowed.');
        }
        $paragraphs = array_filter($publication[0] ?? [], static fn(DOMElement $node): bool => $node->localName === 'paragraph');
        if (count($publication) !== 1 || markdownReleaseDeclarations($paragraphs, $label, atStart: true) !== [$target]
            || markdownReadmeReleaseDeclarations($document, $publication) !== [$target]) {
            throw new RuntimeException($name . ' must have exactly one level-2 publication-state section and one matching release target declaration in a direct paragraph.');
        }
        $installation = markdownSections($document, $installationTitle);
        if (count($installation) !== 1 || ! markdownHasCanonicalInstallation($installation[0], $install)) {
            throw new RuntimeException($name . ' must have exactly one level-2 installation section with one direct Bash fence containing only the canonical installation command.');
        }
        if ($name === 'README.md') {
            $images = markdownImageDestinations($document);
            $badgeTarget = str_replace('-', '--', $target);
            if (! array_any($images, static fn(string $image): bool => str_contains($image, '/badge/Artifact%20target-' . $badgeTarget . '-'))
                || ! array_any($images, static fn(string $image): bool => str_contains($image, '/badge/Install%20after%20publication-' . $badgeTarget . '%40RC-'))) {
                throw new RuntimeException('README.md must show the exact release target in its Artifact target and Install after publication badges.');
            }
        }
    }

    $changelog = parseReleaseMarkdown(artifactDocument($artifact, 'CHANGELOG.md'), 'CHANGELOG.md');
    $headings = markdownReleaseHeadings($changelog);
    $firstTitle = $headings[0]['title'] ?? null;
    $unreleased = $firstTitle === '[Unreleased]' || ($firstTitle === 'Unreleased' && $headings[0]['linked']);
    $release = $headings[$unreleased ? 1 : 0] ?? null;
    if ($release === null || $release['linked'] || ! in_array($release['title'], [$target, '[' . $target . ']'], true)) {
        throw new RuntimeException('CHANGELOG.md must put the exact undated target in its first release section.');
    }
    $quotedTarget = preg_quote($target, '/');
    $targetHeading = '/\A(?:' . $quotedTarget . '|\[' . $quotedTarget . '\])(?=\z|\h)/u';
    if (count(array_filter($headings, static fn(array $heading): bool => preg_match($targetHeading, $heading['title']) === 1)) !== 1) {
        throw new RuntimeException('CHANGELOG.md contains more than one section for the exact release target.');
    }
    $security = parseReleaseMarkdown(artifactDocument($artifact, 'SECURITY.md'), 'SECURITY.md');
    if (! markdownHasUnsupportedRelease($security, $target)) {
        throw new RuntimeException('SECURITY.md must identify the release target and mark it unsupported without conflicting rows.');
    }
}

/** Reads a regular document blob from the candidate commit even when worktree flags hide changes. */
function inactiveMetadataDocument(string $checkout, string $sha, string $name): ?DOMElement
{
    $entry = readReleaseToolOutput(['git', '-C', $checkout, 'ls-tree', $sha, '--', $name]);
    if ($entry === null || preg_match('/\A100(?:644|755) blob ([0-9a-f]{40})\t' . preg_quote($name, '/') . '\n\z/', $entry, $match) !== 1) {
        return null;
    }
    $contents = readReleaseToolOutput(['git', '-C', $checkout, 'cat-file', 'blob', $match[1]]);
    if ($contents === null || $contents === '') {
        return null;
    }
    $document = parseReleaseMarkdown($contents, $name);

    return markdownHasVisibleContent($document) ? $document : null;
}

/** Verifies that the candidate's visible release metadata permits the inactive gate. */
function verifyInactiveRelease(string $checkout, string $sha): int
{
    $documents = [];
    foreach ([...array_keys(RELEASE_READMES), 'CHANGELOG.md'] as $name) {
        $document = inactiveMetadataDocument($checkout, $sha, $name);
        if ($document === null) {
            fwrite(STDERR, 'Cannot determine release preparation state from ' . $name . '.' . PHP_EOL);

            return 1;
        }
        $documents[$name] = $document;
    }

    $active = [];
    foreach (RELEASE_READMES as $name => [$title]) {
        $document = $documents[$name];
        $publication = markdownSections($document, $title);
        if (array_any($publication, markdownHasRawHtmlBlocks(...))) {
            fwrite(STDERR, $name . ' publication-state section must use Markdown. Raw HTML blocks are not allowed.' . PHP_EOL);

            return 1;
        }
        if (markdownReadmeReleaseDeclarations($document, $publication) !== []) {
            $active[] = $name;
        }
    }
    $version = '[0-9]+\.[0-9]+\.[0-9]+-rc\.[0-9]+';
    $pattern = '/\A(?:' . $version . '|\[' . $version . '\])\z/';
    if (array_any(markdownReleaseHeadings($documents['CHANGELOG.md']), static fn(array $heading): bool => preg_match($pattern, $heading['title']) === 1)) {
        $active[] = 'CHANGELOG.md';
    }
    if ($active !== []) {
        fwrite(STDERR, "Set RELEASE_ARTIFACT_TARGET to the exact RC being prepared.\nActive release metadata found in:\n" . implode("\n", $active) . PHP_EOL);

        return 1;
    }
    echo "Release Artifact Verification is not applicable. No release target is active.\n";

    return 0;
}

try {
    if ($argc === 4 && $argv[1] === '--inactive') {
        exit(verifyInactiveRelease($argv[2], $argv[3]));
    }
    if ($argc !== 7) {
        fwrite(STDERR, "Use the shell driver to run Release Artifact Verification.\n");
        exit(2);
    }
    [, $checkout, $candidateTree, $target, $gitTar, $composerZip, $populatedZip] = $argv;
    $expected = [];
    foreach (requiredBlobs($candidateTree) as $path => $blob) {
        assertSafeArchivePath($path);
        $sourcePath = $checkout . '/' . $path;
        if (! is_file($sourcePath) || is_link($sourcePath)) {
            throw new RuntimeException('The exact candidate checkout lacks a regular required file: ' . $path);
        }
        $contents = readArtifactInput($sourcePath);
        if (artifactBlobId($contents) !== $blob) {
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
