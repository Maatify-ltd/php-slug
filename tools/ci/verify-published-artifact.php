<?php

declare(strict_types=1);

require __DIR__ . '/release-artifact-files.php';
require __DIR__ . '/release-artifact-markdown.php';

/** Reads a Composer JSON object. */
function publishedComposerJson(string $path): array
{
    $decoded = json_decode(readArtifactInput($path), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($decoded)) {
        throw new RuntimeException('Expected Composer JSON object: ' . $path);
    }

    return $decoded;
}

/** Creates the isolated consumer manifest, granting stability only to exact RCs. */
function publishedConsumerManifest(string $target, array $release): array
{
    $requirements = ['maatify/php-slug' => $target . '@RC'];
    $constraint = $release['require']['maatify/php-persistence'] ?? null;
    $version = '(0|[1-9][0-9]*)';
    $rc = '(' . $version . '\\.' . $version . '\\.' . $version . '-rc\\.' . $version . ')';
    if (is_string($constraint) && (preg_match('/\\A=?' . $rc . '\\z/', $constraint, $match) === 1
        || preg_match('/\\A>=' . $rc . '\\h+<=\\1\\z/', $constraint, $match) === 1)) {
        $requirements['maatify/php-persistence'] = $match[1] . '@RC';
    }

    return [
        'require' => $requirements,
        'minimum-stability' => 'stable',
        'prefer-stable' => true,
        'config' => ['preferred-install' => 'dist'],
    ];
}

/** Checks consumer-facing identity and installation instructions in the installed package. */
function assertPublishedPresentation(string $packagePath, string $target, array $requirements): void
{
    $command = 'composer require';
    foreach ($requirements as $name => $constraint) {
        $command .= ' ' . $name . ':' . $constraint;
    }
    $readmes = ['README.md' => 'Installation', 'README_AR.md' => 'التثبيت'];
    foreach ($readmes as $name => $heading) {
        $readme = parseReleaseMarkdown(readArtifactInput($packagePath . '/' . $name), $name);
        $sections = markdownSections($readme, $heading);
        if (count($sections) !== 1 || ! markdownHasCanonicalInstallation($sections[0], $command)) {
            throw new RuntimeException($name . ' does not present the exact installed release in its install command.');
        }
    }
    $badgeVersion = str_replace('-', '--', $target);
    $images = markdownImageDestinations(parseReleaseMarkdown(readArtifactInput($packagePath . '/README.md'), 'README.md'));
    $versionBadge = array_any(
        $images,
        static fn(string $image): bool => str_contains($image, '/badge/Artifact%20target-' . $badgeVersion . '-')
            || str_contains($image, '/badge/Version-' . $badgeVersion . '-'),
    );
    $installBadge = array_any(
        $images,
        static fn(string $image): bool => str_contains($image, '/badge/Install%20after%20publication-' . $badgeVersion . '%40RC-')
            || str_contains($image, '/badge/Install-' . $badgeVersion . '%40RC-'),
    );
    if (! $versionBadge || ! $installBadge) {
        throw new RuntimeException('README.md does not have the exact artifact version and install badges.');
    }
    $changelog = parseReleaseMarkdown(readArtifactInput($packagePath . '/CHANGELOG.md'), 'CHANGELOG.md');
    $headings = markdownReleaseHeadings($changelog);
    $firstRelease = array_find($headings, static fn(array $heading): bool => ! in_array($heading['title'], ['Unreleased', '[Unreleased]'], true));
    $exactHeading = '/\A(?:' . preg_quote($target, '/') . '|\[' . preg_quote($target, '/') . '\])(?: \- [0-9]{4}-[0-9]{2}-[0-9]{2})?\z/';
    if ($firstRelease === null || preg_match($exactHeading, $firstRelease['title']) !== 1) {
        throw new RuntimeException('CHANGELOG.md does not identify the exact installed release.');
    }

    assertPackageReferenceReleaseTarget(readArtifactInput($packagePath . '/SLUG_PACKAGE_REFERENCE.md'), $target);

    $security = parseReleaseMarkdown(readArtifactInput($packagePath . '/SECURITY.md'), 'SECURITY.md');
    if (! markdownHasUnsupportedRelease($security, $target)) {
        throw new RuntimeException('SECURITY.md does not identify the exact installed RC support state.');
    }
}

/** Verifies observed delivery metadata, exact release bytes and distribution presentation. */
function verifyPublishedArtifact(string $target, string $reference, string $tree, string $project): void
{
    $installed = publishedComposerJson($project . '/vendor/composer/installed.json');
    $packages = array_values(array_filter($installed['packages'] ?? [], static fn(array $package): bool => ($package['name'] ?? null) === 'maatify/php-slug'));
    if (count($packages) !== 1) {
        throw new RuntimeException('Expected one installed maatify/php-slug package.');
    }
    $package = $packages[0];
    $dist = $package['dist'] ?? [];
    $source = $package['source'] ?? [];
    printf(
        "PAV_PACKAGE=%s\nPAV_VERSION=%s\nPAV_INSTALLATION_SOURCE=%s\nPAV_DIST_TYPE=%s\nPAV_DIST_URL=%s\nPAV_DIST_REFERENCE=%s\nPAV_SOURCE_REFERENCE=%s\n",
        $package['name'],
        $package['version'] ?? '',
        $package['installation-source'] ?? '',
        $dist['type'] ?? '',
        $dist['url'] ?? '',
        $dist['reference'] ?? '',
        $source['reference'] ?? '',
    );
    if (! in_array($package['version'] ?? null, [$target, 'v' . $target], true)
        || ($package['installation-source'] ?? null) !== 'dist'
        || ($dist['type'] ?? null) !== 'zip'
        || ! is_string($dist['url'] ?? null)
        || ! str_starts_with($dist['url'], 'https://')
        || ($dist['reference'] ?? null) !== $reference
        || (isset($source['reference']) && $source['reference'] !== $reference)) {
        throw new RuntimeException('Installed Zip dist identity or Git reference does not match the qualified release.');
    }
    $packagePath = realpath($project . '/vendor/composer/' . ($package['install-path'] ?? ''));
    if ($packagePath === false || $packagePath !== realpath($project . '/vendor/maatify/php-slug')) {
        throw new RuntimeException('Composer did not report the expected installed package directory.');
    }
    printf("PAV_INSTALLED_PATH=%s\n", $packagePath);

    $checksums = [];
    foreach (requiredBlobs($tree) as $file => $blob) {
        assertSafeArchivePath($file);
        $path = $packagePath . '/' . $file;
        if (! is_file($path) || is_link($path) || realpath($path) !== $path) {
            throw new RuntimeException('Required regular file missing in dist: ' . $file);
        }
        $contents = readArtifactInput($path);
        if (artifactBlobId($contents) !== $blob) {
            throw new RuntimeException('Installed file differs from the qualified Git blob: ' . $file);
        }
        $checksums[$file] = hash('sha256', $contents);
    }
    printf("PAV_REQUIRED_FILE_COUNT=%d\n", count($checksums));
    printf("PAV_REQUIRED_PAYLOAD_SHA256=%s\n", hash('sha256', json_encode($checksums, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));

    $consumer = publishedComposerJson($project . '/composer.json');
    assertPublishedPresentation($packagePath, $target, $consumer['require']);

    // The distributed payload must match the qualified package files.
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($packagePath, FilesystemIterator::SKIP_DOTS)) as $entry) {
        $relative = substr($entry->getPathname(), strlen($packagePath) + 1);
        assertSafeArchivePath($relative);
        if (! isset($checksums[$relative]) || ! $entry->isFile() || $entry->isLink()) {
            throw new RuntimeException('Unexpected installed entry: ' . $relative);
        }
    }
    echo "PAV_PRESENTATION_STRUCTURE=PASS\nPAV=SEMANTIC_REVIEW_REQUIRED\n";
}

try {
    if ($argc === 4 && $argv[1] === 'prepare') {
        echo json_encode(publishedConsumerManifest($argv[2], publishedComposerJson($argv[3])), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit(0);
    }
    if ($argc !== 6 || $argv[1] !== 'verify') {
        throw new RuntimeException('Use the shell driver to run Published Artifact Verification.');
    }
    verifyPublishedArtifact($argv[2], $argv[3], $argv[4], $argv[5]);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Published Artifact Verification failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
