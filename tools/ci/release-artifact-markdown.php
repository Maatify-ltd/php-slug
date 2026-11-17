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
    return match ($node->localName) {
        'html_block', 'html_inline', 'image', 'code_block' => '',
        'text' => $node->textContent,
        'code' => $maskInlineCode ? str_repeat("\0", strlen($node->textContent)) : $node->textContent,
        'softbreak', 'linebreak' => ' ',
        default => implode('', array_map(static fn(DOMElement $child): string => markdownVisibleText($child, $maskInlineCode), markdownElements($node))),
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

/** @return list<string|null> Declared release targets, with null for a label lacking a valid RC */
function markdownReleaseDeclarations(DOMElement $document): array
{
    $declarations = [];
    foreach (markdownElements($document, 'paragraph') as $paragraph) {
        // Inline code values remain available, but inline code cannot supply the declaration label.
        $text = markdownVisibleText($paragraph);
        $labelsOnly = markdownVisibleText($paragraph, true);
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

/** Finds an exact installation line in a Bash block in the named top-level section. */
function markdownInstallationContains(DOMElement $document, string $section, string $install): bool
{
    $inInstallation = false;
    foreach (markdownElements($document) as $node) {
        if ($node->localName === 'heading' && in_array($node->getAttribute('level'), ['1', '2'], true)) {
            $inInstallation = $node->getAttribute('level') === '2' && trim(markdownVisibleText($node)) === $section;
        }
        if ($inInstallation && $node->localName === 'code_block' && preg_match('/\Abash(?:\s|\z)/', $node->getAttribute('info')) === 1
            && in_array($install, explode("\n", $node->textContent), true)) {
            return true;
        }
    }

    return false;
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
    foreach (markdownElements($document, 'table') as $table) {
        foreach (markdownElements($table, 'table_row') as $row) {
            $columns = array_map(markdownVisibleText(...), markdownElements($row, 'table_cell'));
            if ($columns === [$target . ' pre-release (artifact target)', 'No']) {
                return true;
            }
        }
    }

    return false;
}
