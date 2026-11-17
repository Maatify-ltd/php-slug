<?php

declare(strict_types=1);

/**
 * Runs a read-only verification tool through an argument vector.
 *
 * @param list<string> $command
 */
function readReleaseToolOutput(array $command, string $contents = ''): ?string
{
    $streams = [];
    try {
        for ($descriptor = 0; $descriptor < 3; $descriptor++) {
            $stream = tmpfile();
            if ($stream === false) {
                throw new RuntimeException('Unable to open release verification streams.');
            }
            $streams[] = $stream;
        }
        [$input, $output] = $streams;
        if (fwrite($input, $contents) !== strlen($contents) || ! rewind($input)) {
            throw new RuntimeException('Unable to prepare release verification input.');
        }
        $process = proc_open($command, $streams, $pipes);
        if (! is_resource($process) || proc_close($process) !== 0 || ! rewind($output)) {
            return null;
        }
        $result = stream_get_contents($output);

        return $result === false ? null : $result;
    } finally {
        foreach ($streams as $stream) {
            fclose($stream);
        }
    }
}

/** Parses a document to the CommonMark AST without evaluating HTML or loading XML resources. */
function parseReleaseMarkdown(string $contents, string $name): DOMElement
{
    $xml = readReleaseToolOutput(['cmark-gfm', '--to', 'xml', '--extension', 'table'], $contents);
    if ($xml === null) {
        throw new RuntimeException('Markdown parser failed for ' . $name . '.');
    }
    $document = new DOMDocument();
    if ($xml === '' || ! $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
        throw new RuntimeException('Markdown parser returned invalid XML for ' . $name . '.');
    }
    $root = $document->documentElement;
    if ($root === null || $root->localName !== 'document' || $root->namespaceURI !== 'http://commonmark.org/xml/1.0') {
        throw new RuntimeException('Markdown parser did not return a CommonMark document for ' . $name . '.');
    }

    return $root;
}

/** @return list<DOMElement> */
function markdownElements(DOMElement $parent, ?string $type = null): array
{
    $elements = [];
    foreach ($parent->childNodes as $node) {
        if ($node instanceof DOMElement && ($type === null || $node->localName === $type)) {
            $elements[] = $node;
        }
    }

    return $elements;
}

/** Returns visible inline text while ignoring HTML, images, and fenced examples. */
function markdownVisibleText(DOMElement $node, bool $maskInlineCode = false): string
{
    $separator = in_array($node->localName, ['table_header', 'table_row'], true) ? ' ' : '';
    return match ($node->localName) {
        'html_block', 'html_inline', 'image', 'code_block' => '',
        'text' => $node->textContent,
        'code' => $maskInlineCode ? str_repeat("\0", strlen($node->textContent)) : $node->textContent,
        'softbreak', 'linebreak' => ' ',
        default => implode($separator, array_map(static fn(DOMElement $child): string => markdownVisibleText($child, $maskInlineCode), markdownElements($node))),
    };
}

/** Recognizes substantive visible content without treating comments as a document. */
function markdownHasVisibleContent(DOMElement $node): bool
{
    return match ($node->localName) {
        'html_block', 'html_inline' => false,
        'image' => $node->getAttribute('destination') !== '',
        'text', 'code', 'code_block' => preg_match('/[^\s\p{Z}]/u', $node->textContent) === 1,
        default => array_any(markdownElements($node), markdownHasVisibleContent(...)),
    };
}

/** @return Generator<int, DOMElement> */
function markdownDeclarationBlocks(DOMElement $container): Generator
{
    $inPublicationState = false;
    $exampleLevel = null;
    $precedingExample = false;
    foreach (markdownElements($container) as $node) {
        if ($container->localName === 'document' && $node->localName === 'heading') {
            $level = (int) $node->getAttribute('level');
            $title = trim(markdownVisibleText($node));
            if ($level <= 2) {
                $inPublicationState = $level === 2 && strcasecmp($title, 'Publication State') === 0;
            }
            if ($exampleLevel !== null && $level <= $exampleLevel) {
                $exampleLevel = null;
            }
            if ($inPublicationState && $exampleLevel === null && preg_match('/\bexamples?\b/i', $title) === 1) {
                $exampleLevel = $level;
            }
        }
        if (in_array($node->localName, ['paragraph', 'heading', 'table_header', 'table_row'], true)) {
            yield $node;
        } elseif (in_array($node->localName, ['list', 'item', 'table'], true)) {
            yield from markdownDeclarationBlocks($node);
        } elseif ($node->localName === 'block_quote' && (markdownIsGitHubAlert($node)
            || ($inPublicationState && $exampleLevel === null && ! $precedingExample
                && ! markdownStartsWithExampleLabel($node)))) {
            yield from markdownDeclarationBlocks($node);
        }
        if (! in_array($node->localName, ['html_block', 'html_inline'], true)) {
            $precedingExample = $node->localName === 'paragraph' && markdownIsExampleLabel(markdownVisibleText($node, true));
        }
    }
}

function markdownIsExampleLabel(string $text): bool
{
    return preg_match('/\A(?:[\w-]+\h+){0,3}examples?\h*:/i', trim($text)) === 1;
}

function markdownStartsWithExampleLabel(DOMElement $quote): bool
{
    $first = markdownElements($quote)[0] ?? null;

    return $first?->localName === 'paragraph' && markdownIsExampleLabel(markdownVisibleText($first, true));
}

/** A GitHub alert starts with its marker alone on the first quoted line. */
function markdownIsGitHubAlert(DOMElement $quote): bool
{
    $firstBlock = markdownElements($quote)[0] ?? null;
    if ($firstBlock?->localName !== 'paragraph') {
        return false;
    }

    $firstLine = markdownElements($firstBlock);
    $marker = $firstLine[0] ?? null;
    $afterMarker = $firstLine[1] ?? null;

    return $marker?->localName === 'text'
        && preg_match('/\A\[!(?:NOTE|TIP|IMPORTANT|WARNING|CAUTION)\]\z/', rtrim($marker->textContent)) === 1
        && ($afterMarker === null || in_array($afterMarker->localName, ['softbreak', 'linebreak'], true));
}

/** @return list<string|null> Declared release targets, with null for a label lacking a valid RC */
function markdownReleaseDeclarations(DOMElement $document): array
{
    $declarations = [];
    foreach (markdownDeclarationBlocks($document) as $block) {
        // Inline code values remain available, but inline code cannot supply the declaration label.
        $text = markdownVisibleText($block);
        $labelsOnly = markdownVisibleText($block, true);
        if (preg_match_all('/(?<![\p{L}\p{N}_])release\h+target:/iu', $labelsOnly, $matches, PREG_OFFSET_CAPTURE) === false) {
            throw new RuntimeException('Unable to parse README release target declarations.');
        }
        foreach ($matches[0] as [$label, $offset]) {
            $value = substr($text, $offset + strlen($label));
            $version = preg_match('/\A\s*([0-9]+\.[0-9]+\.[0-9]+-rc\.[0-9]+)(?=\z|[\s,;:)]|\.(?:\s|\z))/i', $value, $match) === 1
                ? strtolower($match[1]) : null;
            $declarations[] = $version;
        }
    }

    return $declarations;
}

/** @return list<string> */
function markdownParagraphs(DOMElement $document): array
{
    return array_map(markdownVisibleText(...), markdownElements($document, 'paragraph'));
}

/** @return list<array{title: string, linked: bool}> */
function markdownReleaseHeadings(DOMElement $document): array
{
    $headings = [];
    foreach (markdownElements($document, 'heading') as $node) {
        $title = trim(markdownVisibleText($node));
        if ($node->getAttribute('level') === '2' && $title !== '') {
            $headings[] = [
                'title' => $title,
                'linked' => $node->getElementsByTagNameNS('http://commonmark.org/xml/1.0', 'link')->length > 0,
            ];
        }
    }

    return $headings;
}

/** @return list<string> Bash blocks inside the named top-level section. */
function markdownInstallationBlocks(DOMElement $document, string $section): array
{
    $inInstallation = false;
    $blocks = [];
    foreach (markdownElements($document) as $node) {
        if ($node->localName === 'heading' && in_array($node->getAttribute('level'), ['1', '2'], true)) {
            $inInstallation = $node->getAttribute('level') === '2' && trim(markdownVisibleText($node)) === $section;
        }
        if ($inInstallation) {
            array_push($blocks, ...markdownBashBlocks($node));
        }
    }

    return $blocks;
}

/** @return Generator<int, string> */
function markdownBashBlocks(DOMElement $node): Generator
{
    if ($node->localName === 'code_block' && preg_match('/\Abash(?:\s|\z)/', $node->getAttribute('info')) === 1) {
        yield $node->textContent;
    } elseif (in_array($node->localName, ['list', 'item'], true) || ($node->localName === 'block_quote' && markdownIsGitHubAlert($node))) {
        foreach (markdownElements($node) as $child) {
            yield from markdownBashBlocks($child);
        }
    }
}

/** @param array<string, string> $requiredPins Direct package pins required in installation examples. */
function bashHasContradictoryComposerPin(string $block, array $requiredPins): bool
{
    // Recognize direct Composer commands with literal arguments, without interpreting shell code.
    foreach (bashDirectCommands($block) as $command) {
        if ($command[0] !== 'composer') {
            continue;
        }
        $commandIndex = array_find_key(array_slice($command, 1, preserve_keys: true), static fn(string $token): bool => ! str_starts_with($token, '-'));
        if ($commandIndex === null || $command[$commandIndex] !== 'require') {
            continue;
        }
        foreach (array_slice($command, $commandIndex + 1) as $token) {
            if ($token === '<' || $token === '>') {
                break;
            }
            $argument = str_replace(['"', "'"], '', $token);
            $name = strstr($argument, ':', true) ?: $argument;
            if (isset($requiredPins[$name]) && $argument !== $name . ':' . $requiredPins[$name]) {
                return true;
            }
        }
    }

    return false;
}

/** @return Generator<int, non-empty-list<string>> */
function bashDirectCommands(string $block): Generator
{
    foreach (explode("\n", str_replace("\\\n", '', $block)) as $line) {
        $line = preg_replace('/\A\h*\$\h+/', '', $line) ?? $line;
        preg_match_all('/(?:"[^"]*"|\x27[^\x27]*\x27|[^\s"\x27;|&<>])+|[;|&<>]/', $line, $matches);
        $command = [];
        foreach ($matches[0] as $token) {
            if (str_starts_with($token, '#')) {
                break;
            }
            if (in_array($token, [';', '|', '&'], true)) {
                if ($command !== []) {
                    yield $command;
                    $command = [];
                }
            } else {
                $command[] = $token;
            }
        }
        if ($command !== []) {
            yield $command;
        }
    }
}

/** @return list<string> */
function markdownImageDestinations(DOMElement $document): array
{
    $destinations = [];
    foreach (markdownElements($document, 'paragraph') as $paragraph) {
        foreach ($paragraph->getElementsByTagNameNS('http://commonmark.org/xml/1.0', 'image') as $image) {
            $destinations[] = $image->getAttribute('destination');
        }
    }

    return $destinations;
}

/** Confirms the target support row comes from a visible GFM table. */
function markdownHasUnsupportedRelease(DOMElement $document, string $target): bool
{
    $version = preg_quote($target, '/');
    $versionCell = '/\A(?:' . $version . '|\[' . $version . '\])(?=\h|\z)/u';
    $hasUnsupportedRow = false;
    foreach (markdownElements($document, 'table') as $table) {
        foreach (markdownElements($table, 'table_row') as $row) {
            $columns = array_map(markdownVisibleText(...), markdownElements($row, 'table_cell'));
            if (preg_match($versionCell, trim($columns[0] ?? '')) !== 1) {
                continue;
            }
            if (trim($columns[1] ?? '') !== 'No') {
                return false;
            }
            $hasUnsupportedRow = $hasUnsupportedRow || $columns === [$target . ' pre-release (artifact target)', 'No'];
        }
    }

    return $hasUnsupportedRow;
}
