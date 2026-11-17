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

/**
 * Groups visible top-level nodes under each matching level-two heading.
 *
 * @return list<list<DOMElement>>
 */
function markdownSections(DOMElement $document, string $title): array
{
    $sections = [];
    $section = null;
    foreach (markdownElements($document) as $node) {
        if ($node->localName === 'heading' && in_array($node->getAttribute('level'), ['1', '2'], true)) {
            $section = null;
            if ($node->getAttribute('level') === '2' && trim(markdownVisibleText($node)) === $title) {
                $section = count($sections);
                $sections[] = [];
            }
        } elseif ($section !== null) {
            $sections[$section][] = $node;
        }
    }

    return $sections;
}

/** @return Generator<int, DOMElement> */
function markdownDeclarationBlocks(DOMElement $node, bool $inPublication): Generator
{
    if (in_array($node->localName, ['paragraph', 'heading', 'table_header', 'table_row'], true)) {
        yield $node;
    } elseif (in_array($node->localName, ['list', 'item', 'table'], true)
        || ($node->localName === 'block_quote' && ($inPublication || markdownIsGitHubAlert($node)))) {
        foreach (markdownElements($node) as $child) {
            yield from markdownDeclarationBlocks($child, $inPublication);
        }
    }
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

/**
 * @param iterable<DOMElement> $blocks
 * @return list<string|null> Null indicates a declaration label without a valid RC.
 */
function markdownReleaseDeclarations(iterable $blocks, string $labelPattern, bool $atStart = false): array
{
    $declarations = [];
    $pattern = '/' . ($atStart ? '\A\h*' : '(?<![\p{L}\p{N}_])') . $labelPattern . ':/iu';
    foreach ($blocks as $block) {
        // Inline code may hold the version but cannot hold the declaration label.
        $text = markdownVisibleText($block);
        if (preg_match_all($pattern, markdownVisibleText($block, true), $matches, PREG_OFFSET_CAPTURE) === false) {
            throw new RuntimeException('Unable to parse README release target declarations.');
        }
        foreach ($matches[0] as [$label, $offset]) {
            $value = substr($text, $offset + strlen($label));
            $version = preg_match('/\A\s*([0-9]+\.[0-9]+\.[0-9]+-rc\.[0-9]+)(?=\z|[\s,،;:)]|\.(?:\s|\z))/iu', $value, $match) === 1
                ? $match[1] : null;
            $declarations[] = $version;
        }
    }

    return $declarations;
}

/**
 * @param list<list<DOMElement>> $publicationSections
 * @return list<string|null>
 */
function markdownReadmeReleaseDeclarations(DOMElement $document, array $publicationSections): array
{
    $publicationNodes = array_merge(...$publicationSections);
    $declarations = [];
    foreach (markdownElements($document) as $node) {
        $blocks = markdownDeclarationBlocks($node, in_array($node, $publicationNodes, true));
        array_push($declarations, ...markdownReleaseDeclarations($blocks, '(?:release\h+target|الإصدار\h+المستهدف)'));
    }

    return $declarations;
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

/** @param list<DOMElement> $section */
function markdownHasRawHtmlBlocks(array $section): bool
{
    $namespace = 'http://commonmark.org/xml/1.0';
    foreach ($section as $node) {
        $htmlBlocks = $node->localName === 'html_block'
            ? [$node] : $node->getElementsByTagNameNS($namespace, 'html_block');
        foreach ($htmlBlocks as $html) {
            $visibleHtml = preg_replace('/<!--.*?(?:-->|\z)/s', '', $html->textContent);
            if ($visibleHtml === null || trim($visibleHtml) !== '') {
                return true;
            }
        }
    }

    return false;
}

/** @param list<DOMElement> $section */
function markdownHasCanonicalInstallation(array $section, string $install): bool
{
    if (markdownHasRawHtmlBlocks($section)) {
        return false;
    }

    $namespace = 'http://commonmark.org/xml/1.0';
    $fence = null;
    foreach ($section as $node) {
        if ($node->getElementsByTagNameNS($namespace, 'code_block')->length > 0) {
            return false;
        }
        if ($node->localName === 'code_block') {
            if ($fence !== null || $node->getAttribute('info') !== 'bash') {
                return false;
            }
            $fence = $node;
        }
    }
    if ($fence === null) {
        return false;
    }

    $content = trim($fence->textContent);

    return preg_replace('/\h+/', ' ', $content) === $install;
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
