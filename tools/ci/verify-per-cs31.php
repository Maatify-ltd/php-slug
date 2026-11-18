<?php

declare(strict_types=1);

/** Supplemental PER-CS 3.1 checks not established by @PER-CS3x0. */
final class PerCs31Verifier
{
    private const string FALLTHROUGH_APPROVALS = 'tools/ci/per-cs31-fallthrough-approved.json';
    private const string FALLTHROUGH_HEDGE = '(?:maybe|perhaps|possibly|may|might|should)';
    private const string FALLTHROUGH_NEGATIVE = "(?:never|cannot|(?:must|do|does|did|can|will|would|could|should|may|might)\s+not|(?:ca|do|does|did|wo|would|could|should|must)n['’]t)";

    /** @var list<string> */
    private array $errors = [];

    /** @var array<string, array<int, array{case: string, comment: string}>> */
    private array $fallthroughApprovals = [];

    /**
     * Checks the repository PHP sources against the supplemental rules and the root approval file.
     * Returns 1 after reporting a violation or invalid approval data, and 0 when the sources pass.
     */
    public function run(string $root): int
    {
        $files = $this->phpFiles($root);
        try {
            $errors = $this->verifyFiles($files, $root . '/' . self::FALLTHROUGH_APPROVALS);
        } catch (RuntimeException|JsonException $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);

            return 1;
        }

        if ($errors !== []) {
            fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);

            return 1;
        }

        printf("PER-CS 3.1 supplemental verification passed (%d PHP files).%s", count($files), PHP_EOL);

        return 0;
    }

    /**
     * Checks the supplied PHP files and returns the diagnostics for this invocation.
     * A null or missing approval file means no recorded fall-through approvals.
     * Unreadable or malformed approval data stops verification with an exception.
     *
     * @param list<string> $files PHP source paths to inspect.
     * @return list<string> Diagnostics, or an empty list on success.
     */
    public function verifyFiles(array $files, ?string $approvalFile = null): array
    {
        $this->errors = [];
        $this->fallthroughApprovals = $approvalFile === null ? [] : $this->readFallthroughApprovals($approvalFile);

        foreach ($files as $file) {
            $this->verifyFile($file);
        }

        return $this->errors;
    }

    /** @return array<string, array<int, array{case: string, comment: string}>> */
    private function readFallthroughApprovals(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('Unable to read ' . self::FALLTHROUGH_APPROVALS . '.');
        }

        $entries = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new RuntimeException(self::FALLTHROUGH_APPROVALS . ' must contain a JSON list of exact approvals.');
        }

        $approvals = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || count($entry) !== 4
                || !is_string($file = $entry['file'] ?? null) || !str_ends_with($file, '.php')
                || !is_int($line = $entry['line'] ?? null) || $line < 1
                || !is_string($case = $entry['case'] ?? null) || $case === ''
                || !is_string($comment = $entry['comment'] ?? null) || $comment === ''
                || isset($approvals[$file][$line])
            ) {
                throw new RuntimeException(self::FALLTHROUGH_APPROVALS . ' requires unique file, line, case and comment entries.');
            }

            $approvals[$file][$line] = ['case' => $case, 'comment' => $comment];
        }

        return $approvals;
    }

    /** @return list<string> */
    private function phpFiles(string $root): array
    {
        $files = [$root . '/.php-cs-fixer.php'];

        foreach (['src', 'tests', 'examples', 'tools'] as $directory) {
            $path = $root . '/' . $directory;

            if (!is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    private function verifyFile(string $file): void
    {
        if (!is_readable($file)) {
            $this->errors[] = $this->relative($file) . ': unable to read file';

            return;
        }

        $source = file_get_contents($file);

        if ($source === false) {
            $this->errors[] = $this->relative($file) . ': unable to read file';

            return;
        }

        try {
            /** @var list<array{0:int,1:string,2:int}|string> $tokens */
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (Throwable $exception) {
            $this->errors[] = $this->relative($file) . ': parse/tokenizer failure: ' . $exception->getMessage();

            return;
        }

        $this->verifyCloneParentheses($file, $tokens);
        $this->verifySwitches($file, $tokens);
        $this->verifyAnonymousClassAttributes($file, $source, $tokens);
        $this->verifyEnumConstantVisibility($file, $tokens);
        $this->verifyArrayOpeningBracket($file, $tokens);
        $this->verifyMethodArgumentLayout($file, $tokens);
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function verifyCloneParentheses(string $file, array $tokens): void
    {
        // SHOULD only: intentionally non-failing; no repository-local MUST.
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function verifySwitches(string $file, array $tokens): void
    {
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_SWITCH) {
                continue;
            }

            $openParenthesis = $this->nextToken($tokens, $index, '(');
            $closeParenthesis = $openParenthesis === null ? null : $this->matchingToken($tokens, $openParenthesis, '(', ')');
            $openBrace = $closeParenthesis === null ? null : $this->nextToken($tokens, $closeParenthesis, '{');
            $closeBrace = $openBrace === null ? null : $this->matchingToken($tokens, $openBrace, '{', '}');

            if ($openBrace === null || $closeBrace === null) {
                $this->error($file, $token[2], 'switch body is not structurally closed');

                continue;
            }

            $cases = $this->directCaseTokens($tokens, $openBrace, $closeBrace);

            foreach ($cases as $caseOffset => $caseIndex) {
                $nextCase = $cases[$caseOffset + 1] ?? $closeBrace;
                $colon = $this->caseColon($tokens, $caseIndex, $nextCase);

                if ($colon === null) {
                    $this->error($file, $this->tokenLine($tokens, $caseIndex), 'case header has no terminating colon');

                    continue;
                }

                $bodyStart = $this->nextMeaningful($tokens, $colon) ?? $nextCase;

                if ($bodyStart < $nextCase && $tokens[$bodyStart] === '{') {
                    $this->error($file, $this->tokenLine($tokens, $caseIndex), 'case bodies MUST NOT be wrapped in braces');
                }

                $caseToken = $tokens[$caseIndex];

                if (is_array($caseToken) && $caseToken[0] === T_CASE && $this->tokenLine($tokens, $colon) > $caseToken[2]) {
                    $this->verifyMultilineCaseHeader($file, $tokens, $caseIndex, $colon);
                }

                if ($this->significantTokens($tokens, $bodyStart, $nextCase) === []) {
                    continue;
                }

                if (!$this->hasDirectCaseTermination($tokens, $bodyStart, $nextCase)) {
                    $caseLine = $this->tokenLine($tokens, $caseIndex);
                    if ($nextCase < $closeBrace) {
                        $approval = $this->fallthroughApprovals[$this->relative($file)][$caseLine] ?? null;
                        $label = $this->caseLabel($tokens, $caseIndex, $colon);
                        $approvedComment = $approval !== null && $approval['case'] === $label ? $approval['comment'] : null;

                        if ($this->hasFallThroughComment($tokens, $colon + 1, $nextCase, $approvedComment)) {
                            continue;
                        }
                    }

                    $message = $nextCase < $closeBrace
                        ? 'non-empty fall-through case MUST have a clear comment; review unrecognized wording in ' . self::FALLTHROUGH_APPROVALS
                        : 'every non-empty case MUST have a terminating statement';
                    $this->error($file, $caseLine, $message);
                }
            }
        }
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function directCaseTokens(array $tokens, int $openBrace, int $closeBrace): array
    {
        $cases = [];
        $depth = 0;

        for ($index = $openBrace + 1; $index < $closeBrace; $index++) {
            $token = $tokens[$index];

            if ($token === '{') {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
            } elseif ($depth === 0 && is_array($token) && in_array($token[0], [T_CASE, T_DEFAULT], true)) {
                $cases[] = $index;
            }
        }

        return $cases;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function caseColon(array $tokens, int $caseIndex, int $limit): ?int
    {
        $nesting = [];
        $ternaries = 0;

        for ($index = $caseIndex + 1; $index < $limit; $index++) {
            $token = $tokens[$index];

            if ($token === '?' && $nesting === []) {
                $ternaries++;
            } elseif ($token === ':' && $nesting === []) {
                if ($ternaries > 0) {
                    $ternaries--;
                } else {
                    return $index;
                }
            } else {
                $this->updateListNesting($token, $nesting);
            }
        }

        return null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function verifyMultilineCaseHeader(string $file, array $tokens, int $caseIndex, int $colon): void
    {
        $first = $this->nextMeaningful($tokens, $caseIndex);
        $last = $this->previousMeaningful($tokens, $colon);

        if ($first === null || $last === null || $tokens[$first] !== '(' || $tokens[$last] !== ')') {
            $this->error($file, $this->tokenLine($tokens, $caseIndex), 'multi-line case conditions MUST use the PER-CS 3.1 parenthesized layout');

            return;
        }

        if ($this->tokenLine($tokens, $first) !== $this->tokenLine($tokens, $caseIndex) || $this->tokenLine($tokens, $last) !== $this->tokenLine($tokens, $colon)) {
            $this->error($file, $this->tokenLine($tokens, $caseIndex), 'multi-line case parentheses have invalid line placement');
        }
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function hasDirectCaseTermination(array $tokens, int $start, int $end): bool
    {
        $braceDepth = 0;
        $parentheses = 0;
        $brackets = 0;
        $statement = [];
        $terminated = false;
        $pendingTerminator = false;

        for ($index = $start; $index < $end; $index++) {
            $token = $tokens[$index];

            if ($token === '{') {
                $braceDepth++;
                $statement = [];
                continue;
            }
            if ($token === '}') {
                $braceDepth--;
                $statement = [];
                continue;
            }
            if ($token === '(') {
                $parentheses++;
            } elseif ($token === ')') {
                $parentheses--;
            } elseif ($token === '[') {
                $brackets++;
            } elseif ($token === ']') {
                $brackets--;
            }

            if ($braceDepth !== 0) {
                continue;
            }

            if ($pendingTerminator) {
                if ($parentheses === 0 && $brackets === 0 && $token === ';') {
                    $terminated = true;
                    $pendingTerminator = false;
                    $statement = [];
                }

                continue;
            }

            if ($parentheses === 0 && $brackets === 0 && $token === ';') {
                $statement = [];

                continue;
            }

            if ($parentheses !== 0 || $brackets !== 0 || !is_array($token) || in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if ($terminated) {
                return false;
            }

            if (in_array($token[0], [T_BREAK, T_CONTINUE, T_RETURN, T_THROW, T_GOTO, T_EXIT], true) && $statement === []) {
                $pendingTerminator = true;
            }

            $statement[] = $token;
        }

        return $terminated;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function verifyAnonymousClassAttributes(string $file, string $source, array $tokens): void
    {
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_NEW) {
                continue;
            }

            $attribute = $this->nextMeaningful($tokens, $index);

            if ($attribute === null || !is_array($tokens[$attribute]) || $tokens[$attribute][0] !== T_ATTRIBUTE) {
                continue;
            }

            $newLine = $token[2];
            $newIndent = $this->lineIndent($source, $newLine);
            $lastAttributeLine = $newLine;
            $attributeIndent = null;
            $cursor = $attribute;

            do {
                $attributeToken = $tokens[$cursor];
                $attributeLine = $attributeToken[2];
                $currentIndent = $this->lineIndent($source, $attributeLine);
                $attributeIndent ??= $currentIndent;

                if ($attributeLine <= $newLine || $currentIndent !== $newIndent + 4 || $currentIndent !== $attributeIndent) {
                    $this->error($file, $attributeLine, 'anonymous-class attributes MUST start on a more-indented line after new');
                }

                $end = $this->attributeEnd($tokens, $cursor);

                if ($end === null) {
                    $this->error($file, $attributeLine, 'anonymous-class attribute block is not structurally closed');

                    continue 2;
                }

                $lastAttributeLine = $this->tokenLine($tokens, $end);
                $cursor = $this->nextMeaningful($tokens, $end);
            } while ($cursor !== null && is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_ATTRIBUTE);

            if ($cursor === null || !is_array($tokens[$cursor]) || $tokens[$cursor][0] !== T_CLASS) {
                $this->error($file, $lastAttributeLine, 'anonymous-class attributes MUST be followed by class');

                continue;
            }

            $classLine = $tokens[$cursor][2];

            if ($classLine <= $lastAttributeLine || $this->lineIndent($source, $classLine) !== $attributeIndent) {
                $this->error($file, $classLine, 'anonymous class MUST start on a new line with attribute indentation');
            }
        }
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function attributeEnd(array $tokens, int $attribute): ?int
    {
        $depth = 1;

        for ($index = $attribute + 1, $count = count($tokens); $index < $count; $index++) {
            if ($tokens[$index] === '[') {
                $depth++;
            } elseif ($tokens[$index] === ']') {
                $depth--;
            }

            if ($depth === 0) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function verifyEnumConstantVisibility(string $file, array $tokens): void
    {
        $pendingEnum = false;
        $scopes = [];

        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_ENUM) {
                $pendingEnum = true;
                continue;
            }

            if ($token === '{') {
                $scopes[] = $pendingEnum ? 'enum' : 'other';
                $pendingEnum = false;
                continue;
            }

            if ($token === '}') {
                array_pop($scopes);
                continue;
            }

            if (is_array($token) && $token[0] === T_PROTECTED && end($scopes) === 'enum') {
                $next = $this->nextMeaningful($tokens, $index);

                if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_CONST) {
                    $this->error($file, $token[2], 'enum non-public constants MUST use private, not protected');
                }
            }
        }
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function verifyArrayOpeningBracket(string $file, array $tokens): void
    {
        foreach ($tokens as $index => $token) {
            if ($token !== '[' || $index === 0) {
                continue;
            }

            $previous = $this->previousMeaningful($tokens, $index);

            if ($previous === null || !$this->isArrayLiteralOpening($tokens, $index, $previous)) {
                continue;
            }

            $line = $this->tokenLine($tokens, $index);
            $next = $this->nextMeaningful($tokens, $index);

            if ($next !== null && $this->tokenLine($tokens, $next) > $line && $this->lineOnlyContainsWhitespaceBefore($tokens, $index)) {
                $this->error($file, $line, 'a multi-line array opening bracket MUST NOT be on its own line');
            }
        }
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function isArrayLiteralOpening(array $tokens, int $index, int $previous): bool
    {
        $close = $this->matchingToken($tokens, $index, '[', ']');

        if ($close !== null) {
            $after = $this->nextMeaningful($tokens, $close);

            if ($after !== null && $tokens[$after] === '=') {
                return false;
            }
        }

        return !$this->isDestructuringOpening($tokens, $index, $previous) && !$this->isExpressionOperand($tokens[$previous]);
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function isDestructuringOpening(array $tokens, int $index, int $previous): bool
    {
        $token = $tokens[$previous];

        if (is_array($token) && $token[0] === T_AS) {
            return true;
        }

        $close = $this->matchingToken($tokens, $index, '[', ']');

        if ($close !== null) {
            $after = $this->nextMeaningful($tokens, $close);

            if ($after !== null && $tokens[$after] === '=') {
                return true;
            }
        }

        if ($token !== ',' && !(is_array($token) && $token[0] === T_DOUBLE_ARROW)) {
            return false;
        }

        $enclosing = $this->enclosingSquareOpening($tokens, $index);

        if ($enclosing !== null && $this->isDestructuringOpening(
            $tokens,
            $enclosing,
            $this->previousMeaningful($tokens, $enclosing) ?? $enclosing,
        )) {
            return true;
        }

        return is_array($token) && $token[0] === T_DOUBLE_ARROW && $this->hasForeachAsBefore($tokens, $previous);
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function hasForeachAsBefore(array $tokens, int $index): bool
    {
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            $token = $tokens[$cursor];

            if (is_array($token) && $token[0] === T_AS) {
                return true;
            }

            if (in_array($token, [';', '{', '}'], true)) {
                return false;
            }
        }

        return false;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function enclosingSquareOpening(array $tokens, int $index): ?int
    {
        $depth = 0;

        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            if ($tokens[$cursor] === ']') {
                $depth++;
            } elseif ($tokens[$cursor] === '[') {
                if ($depth === 0) {
                    return $cursor;
                }

                $depth--;
            }
        }

        return null;
    }

    /** @param array{0:int,1:string,2:int}|string $token */
    private function isExpressionOperand(array|string $token): bool
    {
        if (in_array($token, [')', ']', '}'], true)) {
            return true;
        }

        if (!is_array($token)) {
            return false;
        }

        return in_array($token[0], [
            T_VARIABLE,
            T_STRING,
            T_STRING_VARNAME,
            T_CONSTANT_ENCAPSED_STRING,
            T_LNUMBER,
            T_DNUMBER,
            T_CLASS_C,
            T_METHOD_C,
            T_LINE,
            T_FILE,
            T_DIR,
            T_NS_C,
        ], true);
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function verifyMethodArgumentLayout(string $file, array $tokens): void
    {
        foreach ($tokens as $index => $token) {
            if ($token !== '(') {
                continue;
            }

            $previous = $this->previousMeaningful($tokens, $index);

            if ($previous === null || ($listKind = $this->argumentListKind($tokens, $index, $previous)) === null) {
                continue;
            }

            $close = $this->matchingToken($tokens, $index, '(', ')');

            if ($close === null || !$this->hasTopLevelNewline($tokens, $index, $close)) {
                continue;
            }

            $starts = [];
            $nesting = [];
            $segmentStart = $this->nextMeaningful($tokens, $index);

            for ($cursor = $index + 1; $cursor < $close; $cursor++) {
                $current = $tokens[$cursor];

                if ($current === ',' && $nesting === []) {
                    if ($segmentStart !== null) {
                        $starts[] = $segmentStart;
                    }
                    $segmentStart = $this->nextMeaningful($tokens, $cursor);

                    if ($segmentStart === $close) {
                        $segmentStart = null;
                    }
                } else {
                    $this->updateListNesting($current, $nesting);
                }
            }

            if ($segmentStart !== null) {
                $starts[] = $segmentStart;
            }

            if ($starts === []) {
                continue;
            }

            $openLine = $this->tokenLine($tokens, $index);
            $openIndent = $this->lineIndentFromTokens($tokens, $index);
            $firstEnd = $this->argumentEnd($tokens, $starts[0], $close);

            if ($this->tokenLine($tokens, $starts[0]) <= $openLine && !$this->argumentHasMultilineNestedValue($tokens, $starts[0], $firstEnd)) {
                $this->error($file, $openLine, 'multiline argument lists MUST start their first argument on the next line');
            }

            if ($this->tokenLine($tokens, $starts[0]) > $openLine && $this->lineIndentFromTokens($tokens, $starts[0]) !== $openIndent + 4) {
                $this->error($file, $this->tokenLine($tokens, $starts[0]), 'multiline argument list items MUST be indented once relative to the opening construct');
            }

            for ($offset = 1; $offset < count($starts); $offset++) {
                $argumentEnd = $this->argumentEnd($tokens, $starts[$offset], $close);
                $sameLine = $this->tokenLine($tokens, $starts[$offset]) === $this->tokenLine($tokens, $starts[$offset - 1]);

                if ($sameLine && !$this->argumentHasMultilineNestedValue($tokens, $starts[$offset], $argumentEnd)) {
                    $this->error($file, $this->tokenLine($tokens, $starts[$offset]), 'multiline argument lists MUST have one argument per line');
                }

                if (!$sameLine && $this->lineIndentFromTokens($tokens, $starts[$offset]) !== $openIndent + 4) {
                    $this->error($file, $this->tokenLine($tokens, $starts[$offset]), 'multiline argument list items MUST be indented once relative to the opening construct');
                }
            }

            if ($listKind !== 'call' && $this->tokenLine($tokens, $close) <= $this->tokenLine($tokens, $starts[count($starts) - 1])) {
                $this->error($file, $this->tokenLine($tokens, $close), 'multiline declaration and closure lists MUST close on their own line after the final item');
            }
        }
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function argumentListKind(array $tokens, int $index, int $previous): ?string
    {
        $token = $tokens[$previous];

        if (!is_array($token)) {
            if ($token === '&') {
                $beforeReference = $this->previousMeaningful($tokens, $previous);

                return $beforeReference !== null && is_array($tokens[$beforeReference]) && in_array($tokens[$beforeReference][0], [T_FUNCTION, T_FN], true)
                    ? 'declaration'
                    : null;
            }

            return in_array($token, [')', ']'], true) ? 'call' : null;
        }

        if ($this->isReferenceToken($token)) {
            $beforeReference = $this->previousMeaningful($tokens, $previous);

            return $beforeReference !== null && is_array($tokens[$beforeReference]) && in_array($tokens[$beforeReference][0], [T_FUNCTION, T_FN], true)
                ? 'declaration'
                : null;
        }

        if (in_array($token[0], [T_IF, T_SWITCH, T_WHILE, T_FOR, T_FOREACH, T_CATCH, T_MATCH], true)) {
            return null;
        }

        if ($token[0] === T_USE && $this->isClosureUseList($tokens, $previous)) {
            return 'closure-use';
        }

        if ($token[0] === T_CLASS) {
            $beforeClass = $this->previousMeaningfulAnonymousClassPrefix($tokens, $previous);

            return $beforeClass !== null && is_array($tokens[$beforeClass]) && $tokens[$beforeClass][0] === T_NEW
                ? 'anonymous-constructor'
                : null;
        }

        if ($token[0] === T_FUNCTION || $token[0] === T_FN) {
            return 'declaration';
        }

        if (in_array($token[0], [T_VARIABLE, T_STRING], true)) {
            return $this->isNamedDeclaration($tokens, $previous) ? 'declaration' : 'call';
        }

        if (defined('T_NAME_QUALIFIED') && in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
            return 'call';
        }

        return null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function isNamedDeclaration(array $tokens, int $nameIndex): bool
    {
        $before = $this->previousMeaningful($tokens, $nameIndex);

        if ($before !== null && is_array($tokens[$before]) && $tokens[$before][0] === T_FUNCTION) {
            return true;
        }

        return $before !== null && $this->isReferenceToken($tokens[$before]) && ($before = $this->previousMeaningful($tokens, $before)) !== null
            && is_array($tokens[$before]) && $tokens[$before][0] === T_FUNCTION;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function isClosureUseList(array $tokens, int $useIndex): bool
    {
        $close = $this->previousMeaningful($tokens, $useIndex);

        if ($close === null || $tokens[$close] !== ')') {
            return false;
        }

        $open = $this->openingParenthesis($tokens, $close);

        if ($open === null) {
            return false;
        }

        $before = $this->previousMeaningful($tokens, $open);

        if ($before !== null && $this->isReferenceToken($tokens[$before])) {
            $before = $this->previousMeaningful($tokens, $before);
        }

        return $before !== null && is_array($tokens[$before]) && $tokens[$before][0] === T_FUNCTION;
    }

    /** @param array{0:int,1:string,2:int}|string $token */
    private function isReferenceToken(array|string $token): bool
    {
        if ($token === '&') {
            return true;
        }

        return is_array($token) && (
            (defined('T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG') && $token[0] === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG)
            || (defined('T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG') && $token[0] === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG)
        );
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function previousMeaningfulAnonymousClassPrefix(array $tokens, int $classIndex): ?int
    {
        $cursor = $this->previousMeaningful($tokens, $classIndex);

        while ($cursor !== null && $tokens[$cursor] === ']') {
            do {
                $cursor--;
            } while ($cursor >= 0 && (!is_array($tokens[$cursor]) || $tokens[$cursor][0] !== T_ATTRIBUTE));

            $cursor = $cursor >= 0 ? $this->previousMeaningful($tokens, $cursor) : null;
        }

        return $cursor;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function openingParenthesis(array $tokens, int $close): ?int
    {
        $depth = 0;

        for ($cursor = $close; $cursor >= 0; $cursor--) {
            if ($tokens[$cursor] === ')') {
                $depth++;
            } elseif ($tokens[$cursor] === '(') {
                $depth--;

                if ($depth === 0) {
                    return $cursor;
                }
            }
        }

        return null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function hasTopLevelNewline(array $tokens, int $start, int $end): bool
    {
        $nesting = [];

        for ($index = $start + 1; $index < $end; $index++) {
            $token = $tokens[$index];

            if ($nesting === [] && is_array($token) && str_contains($token[1], "\n")) {
                return true;
            }

            $this->updateListNesting($token, $nesting);
        }

        return false;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function argumentEnd(array $tokens, int $start, int $close): int
    {
        $nesting = [];

        for ($index = $start; $index < $close; $index++) {
            $token = $tokens[$index];

            if ($token === ',' && $nesting === []) {
                return $index;
            }

            $this->updateListNesting($token, $nesting);
        }

        return $close;
    }

    /** @param array{0:int,1:string,2:int}|string $token
     *  @param list<string> $nesting
     */
    private function updateListNesting(array|string $token, array &$nesting): void
    {
        if (is_array($token) && $token[0] === T_ATTRIBUTE) {
            $nesting[] = ']';

            return;
        }

        if (in_array($token, ['(', '[', '{'], true)) {
            $nesting[] = $token === '(' ? ')' : ($token === '[' ? ']' : '}');

            return;
        }

        if (in_array($token, [')', ']', '}'], true) && end($nesting) === $token) {
            array_pop($nesting);
        }
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function argumentHasMultilineNestedValue(array $tokens, int $start, int $end): bool
    {
        for ($index = $start; $index < $end; $index++) {
            if (!in_array($tokens[$index], ['(', '[', '{'], true)) {
                continue;
            }

            $closing = $tokens[$index] === '(' ? ')' : ($tokens[$index] === '[' ? ']' : '}');
            $match = $this->matchingToken($tokens, $index, $tokens[$index], $closing);

            if ($match !== null && $this->tokenLine($tokens, $match) > $this->tokenLine($tokens, $index)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function nextMeaningful(array $tokens, int $index): ?int
    {
        for ($index++; isset($tokens[$index]); $index++) {
            if (!is_array($tokens[$index]) || !in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function previousMeaningful(array $tokens, int $index): ?int
    {
        for ($index--; $index >= 0; $index--) {
            if (!is_array($tokens[$index]) || !in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function nextToken(array $tokens, int $index, string $expected): ?int
    {
        $next = $this->nextMeaningful($tokens, $index);

        return $next !== null && $tokens[$next] === $expected ? $next : null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function matchingToken(array $tokens, int $open, string $opening, string $closing): ?int
    {
        $depth = 0;

        for ($index = $open, $count = count($tokens); $index < $count; $index++) {
            if ($tokens[$index] === $opening) {
                $depth++;
            } elseif ($tokens[$index] === $closing) {
                $depth--;

                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function significantTokens(array $tokens, int $start, int $end): array
    {
        return array_values(array_filter(
            array_slice($tokens, $start, $end - $start),
            static fn(array|string $token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function caseLabel(array $tokens, int $start, int $colon): string
    {
        return implode('', array_map(
            static fn(array|string $token): string => is_array($token) ? $token[1] : $token,
            array_slice($tokens, $start, $colon - $start + 1),
        ));
    }

    /**
     * Checks top-level comment groups within a case for intentional continuation.
     * Recognized contradictions and uncertainty take precedence over an affirmative marker.
     * An approval must match the captured comment, and attributed case literals are treated as data.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private function hasFallThroughComment(array $tokens, int $start, int $end, ?string $approvedComment): bool
    {
        $nesting = [];
        $commentGroups = [];
        $adjacentComments = [];
        $caseLiterals = [];
        for ($index = $start; $index < $end; $index++) {
            $token = $tokens[$index];
            $this->updateListNesting($token, $nesting);
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $caseLiterals[self::constantStringValue($token[1])] = true;
            }
            if ($nesting === [] && is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $body = trim($token[1], "/*# \t\r\n");
                $comment = preg_replace('/\R[ \t]*\*?[ \t]*/u', ' ', $body);
                if ($comment !== null) {
                    $adjacentComments[] = $comment;
                }
                continue;
            }
            if (is_array($token) && $token[0] === T_WHITESPACE && substr_count($token[1], "\n") < 2) {
                continue;
            }

            if ($adjacentComments !== []) {
                $commentGroups[] = $adjacentComments;
                $adjacentComments = [];
            }
        }

        if ($adjacentComments !== []) {
            $commentGroups[] = $adjacentComments;
        }

        $hasDeclaration = false;
        foreach ($commentGroups as $comments) {
            $flowComments = [];
            foreach ($comments as $comment) {
                $flowComment = self::withoutAttributedLiterals($comment, $caseLiterals);
                $flowComments[] = $flowComment;
                $approved = $comment === $approvedComment;
                if (($approved && self::isUncertainFallthrough($flowComment)) || self::hasUncertainFallthroughClause($flowComment)) {
                    return false;
                }

                $hasDeclaration = $hasDeclaration || $approved
                    || (self::declaresFallthrough($comment) && !self::isUncertainFallthrough($flowComment));
            }

            // Check wrapped instructions that span adjacent comment tokens.
            if (self::opposesFallthrough(implode("\n", $flowComments))) {
                return false;
            }
        }

        return $hasDeclaration;
    }

    /** Reads the value of a T_CONSTANT_ENCAPSED_STRING token without evaluating PHP. */
    private static function constantStringValue(string $token): string
    {
        $quoted = ltrim($token, 'bB');
        $value = substr($quoted, 1, -1);
        if ($quoted[0] === "'") {
            return strtr($value, ["\\'" => "'", "\\\\" => "\\"]);
        }

        $escapePattern = <<<'REGEX'
            ~\\(?:[nrtvef\\$"]|[0-7]{1,3}|x[0-9a-fA-F]{1,2}|u\{[0-9a-fA-F]+\})~
            REGEX;

        return preg_replace_callback($escapePattern, static function (array $match): string {
            $escape = $match[0];

            $decoded = match ($escape[1]) {
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'v' => "\v",
                'e' => "\e",
                'f' => "\f",
                '\\', '$', '"' => $escape[1],
                'x' => chr((int) hexdec(substr($escape, 2))),
                'u' => mb_chr((int) hexdec(substr($escape, 3, -1)), 'UTF-8'),
                default => chr(octdec(substr($escape, 1)) & 0xff),
            };

            return $decoded === false ? $escape : $decoded;
        }, $value) ?? $value;
    }

    /**
     * Removes quoted data explicitly described by the comment and present as a case string literal.
     *
     * @param array<string, true> $caseLiterals
     */
    private static function withoutAttributedLiterals(string $comment, array $caseLiterals): string
    {
        if ($caseLiterals === []) {
            return $comment;
        }

        $quotation = <<<'REGEX'
            ~(?<prefix>\b(?:(?:(?:displayed|printed|rendered|logged|emitted|literal|expected)\s+)?(?:message|text|label|output|string|payload|value)\s*(?:(?:is|reads|says|equals)\s+|[:=]\s*)|(?:prints?|displays?|renders?|logs?|emits?|echoes?)\s+))(?:"(?<double>[^"]+)"|'(?<single>[^']+)'|“(?<curlyDouble>[^”]+)”|‘(?<curlySingle>[^’]+)’|`(?<code>[^`]+)`)~iu
            REGEX;

        return preg_replace_callback(
            $quotation,
            static function (array $match) use ($caseLiterals): string {
                $literal = $match['double'] ?? $match['single'] ?? $match['curlyDouble'] ?? $match['curlySingle'] ?? $match['code'];
                if (!isset($caseLiterals[$literal])) {
                    return $match[0];
                }

                // Keep a sentence boundary so following instructions remain independently visible.
                $boundary = preg_match('~[.!?؟]\z~u', $literal) === 1 ? '.' : '';

                return $match['prefix'] . '[quoted text]' . $boundary;
            },
            $comment,
            flags: PREG_UNMATCHED_AS_NULL,
        ) ?? $comment;
    }

    /** Checks uncertainty in clauses about control flow so unrelated prose does not veto a marker. */
    private static function hasUncertainFallthroughClause(string $comment): bool
    {
        $clauses = preg_split('~(?<=[.!?؟])\s+~u', $comment) ?: [$comment];
        $nextCase = '(?:the\s+)?(?:next|following)\s+case';
        $action = '(?:enter|run|execute|reach|skip|bypass|avoid|(?:continue|proceed|jump|go)\s+(?:into|to|with))';
        $auxiliary = '(?:does|should|will|must|can|could|would|may|might)';
        $reference = '~\b(?:falls?[- ]?through|no\s+break'
            . '|' . $action . '\s+' . $nextCase
            . '|(?:' . $auxiliary . '\s+' . $nextCase . '|' . $nextCase . '(?:\s+' . $auxiliary . ')?)\s+(?:runs?|executes?|be\s+(?:entered|executed|reached|run))'
            . '|(?:break|return|stop|terminate)\s+(?:here|instead)|(?:add|omit|need)\s+(?:a|the)\s+break'
            . '|(?:should|must|do|does|can|could|would|will|may|might)\s+(?:we|it|this\s+case)\s+(?:break|continue|proceed|stop|return|terminate)(?=\s*[.!?؟]|\s*\z)'
            . '|(?:ندخل|تدخل|الدخول|ننفذ|تنفذ|تنفيذ|ننتقل|تنتقل|الانتقال)\s+(?:إلى\s+)?الحالة\s+(?:التالية|القادمة))\b~iu';
        foreach ($clauses as $clause) {
            if (preg_match($reference, $clause) === 1 && self::isUncertainFallthrough($clause)) {
                return true;
            }
        }

        return false;
    }

    /** Recognizes explicit termination and negative continuation directives in a fall-through comment. */
    private static function opposesFallthrough(string $comment): bool
    {
        $target = '(?:the\s+)?(?:next|following)\s+case';
        $negative = self::FALLTHROUGH_NEGATIVE;
        $hedge = self::FALLTHROUGH_HEDGE;
        $execution = '(?:run|execute|be\s+(?:entered|executed|reached|run))';
        $clauseStart = <<<'REGEX'
            (?:\A|[.!?؟:;—\n]\s*)['"“‘`]?\s*
            REGEX;
        $clauseEnd = <<<'REGEX'
            (?=\s*['"”’`]?(?:[.!?؟;]|\z))
            REGEX;
        $termination = '(?:break(?:\s+out)?|return|stop|terminate|exit)';
        $position = '(?:(?:right\s+)?(?:here(?:\s+instead)?|now)|instead)';
        $subject = '(?:we|execution|control|(?:this|the(?:\s+current)?)\s+(?:case|branch))';
        $modal = '(?:must|should|will|shall|can|could|would|may|might|(?:need|needs|have|has)\s+to)';
        $adverb = '(?:(?!(?:barely|hardly|rarely|scarcely)\b)\p{L}+(?:-\p{L}+)*ly'
            . '|please|just|still|first|then|now|at\s+once|right\s+away|without\s+(?:delay|exception)|as\s+soon\s+as\s+possible'
            . '|of\s+course|as\s+(?:usual|expected)|by\s+design|in\s+(?:fact|practice)|for\s+(?:clarity|safety))';
        $separator = '(?:\h*,\s*|\s+)';
        $qualifiers = '(?:(?:' . $adverb . '|\(\h*' . $adverb . '\h*\))' . $separator . ')*+';

        // Paired asides can separate the subject, modal and verb without consuming negation or conditions.
        $aside = '(?![\p{L}\h-]*\b(?:not|no|never|unless|hardly|barely|scarcely|if|when|whenever|until|except|provided|depending\s+on)\b)[\p{L}][\p{L}\h-]*';
        $join = '(?:\h*,\h*' . $aside . '\h*,\s*|\s*\(\h*' . $aside . '\h*\)\s+|\s+)';
        $head = '(?:' . $subject . $join . $qualifiers . $modal . '|' . $modal . $join . $subject . ')';
        $directive = $clauseStart . $qualifiers . '(?:' . $head . $join . ')?' . $qualifiers;
        // After the verb, admit known adjuncts only so a direct object changes the clause's classification.
        $adjunct = '(?:' . $adverb . '|' . $position . ')';
        $actionQualifier = $separator . '(?:' . $adjunct . '|\(\h*' . $adjunct . '\h*\))';
        $afterAction = '(?:' . $actionQualifier . ')*+';
        $timing = '\s+before\s+(?:(?:entering|reaching|running|executing|(?:continuing|proceeding|moving|going)\s+(?:into|to))\s+)?' . $target;

        $fromCallable = '\s+from\s+(?:(?:the|this|our)(?:\s+current)?\s+|current\s+)?(?:function|method)';
        $toCaller = '\s+to\s+(?:(?:the|its|our)\s+)?caller';
        $returnBoundary = '(?:' . $fromCallable . '(?:' . $afterAction . $toCaller . ')?'
            . '|' . $toCaller . '(?:' . $afterAction . $fromCallable . ')?)';

        return self::hasExplicitReturnValue($comment, $directive, $actionQualifier) || preg_match(
            '~\b(?:' . $negative . '|' . $hedge . ')\s+(?:(?:intentionally|deliberately|purposely)\s+)?falls?[- ]?through\b'
            . '|\b' . $target . '\s+(?:(?:' . $negative . '|' . $hedge . ')\s+' . $execution . '|(?:is|will\s+be)\s+skipped)\b'
            . '|\b(?:skip|bypass|avoid)\s+' . $target . '\b'
            . '|\b(?:' . $negative . '|' . $hedge . ')\s+(?:(?:enter|run|execute|reach|use)\s+|(?:continue|proceed)\s+(?:into|to)\s+)' . $target . '\b'
            . '|\b(?:prevent|keep|stop)\s+' . $target . '\s+from\s+(?:running|executing|being\s+(?:entered|reached))\b'
            . '|' . $directive . $termination . $afterAction . '(?:,?' . $timing . '\b|' . $clauseEnd . ')'
            . '|' . $directive . 'return' . $afterAction . $returnBoundary . $afterAction . $clauseEnd
            . '|' . $directive . '(?:add|insert)\s+(?:(?:a|the)\s+)?break(?:\s+statement)?' . $clauseEnd
            . '~iu',
            $comment,
        ) === 1;
    }

    /** Checks PHP-shaped return values for syntax without executing any part of the comment. */
    private static function hasExplicitReturnValue(string $comment, string $directive, string $actionQualifier): bool
    {
        if (preg_match_all('~' . $directive . 'return\b(?=\s|\()~iu', $comment, $returns, PREG_OFFSET_CAPTURE) < 1) {
            return false;
        }

        $clauseBoundary = <<<'REGEX'
            ~;|(?<!\s)[.!؟](?=\s|['"”’`]|\z)|\?(?=\h*['"”’`]?\h*(?:\n|\z))|\z~u
            REGEX;
        $closingQuote = <<<'REGEX'
            ~['"”’`]\s*\z~u
            REGEX;
        $trailingQualifiers = '~(?<![\s,])(?=(?:' . $actionQualifier . ')++\z)~iu';
        $word = '[\p{Ll}\p{Lo}][\p{Ll}\p{Lo}\p{M}]*';
        $hyphenatedWord = $word . '(?:-' . $word . ')+';
        $wordWithAside = $word . '(?:-' . $word . ')*(?=\h+\()(?i:' . $actionQualifier . ')';
        $ambiguousProse = '~\A(?!(?:true|false|null)\h)(?:' . $hyphenatedWord . '|' . $wordWithAside . ')\z~u';

        foreach ($returns[0] as [$prefix, $offset]) {
            $tail = substr($comment, $offset + strlen($prefix));
            preg_match_all($clauseBoundary, $tail, $endings, PREG_OFFSET_CAPTURE);
            foreach ($endings[0] as [, $end]) {
                $value = trim(substr($tail, 0, $end));
                $withoutClosingQuote = preg_replace($closingQuote, '', $value) ?? $value;
                foreach ([$value, trim($withoutClosingQuote)] as $candidate) {
                    $explicit = self::classifyReturnCandidate($candidate, $ambiguousProse);
                    if ($explicit === null && preg_match_all($trailingQualifiers, $candidate, $suffixes, PREG_OFFSET_CAPTURE) > 0) {
                        // Try the longest expressions first, including when recognized suffixes overlap.
                        foreach (array_reverse($suffixes[0]) as [, $offset]) {
                            $explicit = self::classifyReturnCandidate(substr($candidate, 0, $offset), $ambiguousProse);
                            if ($explicit !== null) {
                                break;
                            }
                        }
                    }
                    if ($explicit === true) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** Returns null when the candidate cannot be parsed as a PHP expression. */
    private static function classifyReturnCandidate(string $candidate, string $ambiguousProse): ?bool
    {
        $explicitCode = strlen($candidate) > 1 && str_starts_with($candidate, '`') && str_ends_with($candidate, '`');
        $expression = $explicitCode ? trim(substr($candidate, 1, -1)) : $candidate;
        try {
            $tokens = token_get_all('<?php return (' . $expression . ');', TOKEN_PARSE);
        } catch (ParseError) {
            return null;
        }

        if ($explicitCode) {
            return true;
        }

        // A hyphenated word or a spaced prose aside can look like arithmetic or a call.
        if (preg_match($ambiguousProse, $candidate) === 1) {
            return false;
        }

        // Strip the synthetic opening tag and return statement around the candidate.
        return self::hasPhpExpressionTokens(array_slice($tokens, 4, -2));
    }

    /** @param list<array{int, string, int}|string> $tokens */
    private static function hasPhpExpressionTokens(array $tokens): bool
    {
        $afterNew = false;
        foreach ($tokens as $token) {
            if (is_string($token)) {
                return true;
            }

            [$type, $text] = $token;
            if ($type === T_STRING) {
                // Preserve conventional standalone constants while allowing arbitrary names in expressions.
                if (preg_match('~\A(?:(?i:true|false|null)|[A-Z_][A-Z0-9_]*|[\p{L}][\p{L}\p{N}]*_[\p{L}\p{N}_]+)\z~u', $text) === 1
                    || ($afterNew && preg_match('~\A[A-Z_]~', $text) === 1)
                ) {
                    return true;
                }
            } elseif (!in_array($type, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR, T_NEW], true)) {
                // Literals, qualified names and symbolic operators make the PHP syntax explicit.
                return true;
            }

            if ($type !== T_WHITESPACE) {
                $afterNew = $type === T_NEW;
            }
        }

        return false;
    }

    /** Treats hedges and questions about continuation as insufficient evidence of intent. */
    private static function isUncertainFallthrough(string $comment): bool
    {
        $action = '(?:falls?[- ]?through|omit|skip|leave\s+out|continue|proceed)';
        $hedge = self::FALLTHROUGH_HEDGE;
        $intent = '(?:intentionally|deliberately|purposely)';
        $negative = self::FALLTHROUGH_NEGATIVE;

        return self::hasQuestionMarkOutsideQuotedToken($comment) || preg_match(
            '~\A(?:if|unless|whether|' . $hedge . '|(?:check|consider|decide)\s+whether)\b'
            . '|\b(?:we|execution|the\s+case)\s+' . $hedge . '\s+(?:' . $intent . '\s+)?' . $action . '\b'
            . '|\bso\s+(?:maybe|perhaps|possibly)\b|\bif\s+(?:needed|required|necessary)\b'
            . '|\b' . $negative . '\s+(?:' . $intent . '\s+)?' . $action . '\b'
            . '|\bnot\s+(?:an?\s+)?(?:intentional|deliberate|intended)\b~iu',
            $comment,
        ) === 1;
    }

    /** Exempts a quoted question-mark token while retaining question punctuation in longer quoted prose. */
    private static function hasQuestionMarkOutsideQuotedToken(string $comment): bool
    {
        if (!str_contains($comment, '?') && !str_contains($comment, '؟')) {
            return false;
        }

        // Ignore only the quoted token; a complete quoted question still counts.
        $quotedToken = <<<'REGEX'
            ~(?<![\\\p{L}\p{N}_])(?:'[?؟]'|"[?؟]"|`[?؟]`|“[?؟]”|‘[?؟]’)(?![\p{L}\p{N}_])~u
            REGEX;
        $withoutTokens = preg_replace($quotedToken, '', $comment);

        return $withoutTokens === null || str_contains($withoutTokens, '?') || str_contains($withoutTokens, '؟');
    }

    /** Recognizes supported affirmative continuation markers after other checks assess contradictions. */
    private static function declaresFallthrough(string $comment): bool
    {
        $intent = '(?:intentionally|deliberately|purposely)';
        $terminator = '(?:the\s+)?(?:break|return|termination)(?:\s+statement)?';
        $nextCase = '(?:the\s+)?(?:next|following)\s+case';
        $destination = '(?:into|to)\s+' . $nextCase;
        $continuation = '(?:(?:' . $intent . '\s+)?falls?[- ]?through'
            . '(?:\s+' . $intent . ')?(?:\s+' . $destination . ')?'
            . '|(?:execution\s+)?(?:' . $intent . '\s+)?(?:continues?|proceeds?)\s+' . $destination . ')';
        $omission = '(?:(?:we\s+)?' . $intent . '\s+(?:omit|skip|leave\s+out)\s+' . $terminator
            . '|' . $terminator . '\s+(?:is\s+)?' . $intent . '\s+(?:omitted|skipped|left\s+out))';
        $purpose = '(?:\s+so\s+' . $nextCase . '\s+(?:(?:can|will)\s+(?:run|execute)|runs|executes))?';

        // Match a complete declaration. Additional prose requires an exact review entry.
        return preg_match(
            '~\A(?:' . $continuation
            . '|(?:no\s+break|(?:deliberate|intentional)\s+fall[- ]?through)(?:\s*[,;:—-]\s+' . $continuation . ')?'
            . '|' . $omission . $purpose
            . ')[.!]?\z~iu',
            $comment,
        ) === 1;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function tokenLine(array $tokens, int $index): int
    {
        $line = 1;

        foreach (array_slice($tokens, 0, $index) as $token) {
            $line += substr_count(is_array($token) ? $token[1] : $token, "\n");
        }

        return $line;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function lineIndentFromTokens(array $tokens, int $index): int
    {
        $text = '';

        foreach (array_slice($tokens, 0, $index) as $token) {
            $text .= is_array($token) ? $token[1] : $token;
        }

        $line = substr($text, strrpos($text, "\n") + 1);

        return strlen($line) - strlen(ltrim($line, " \t"));
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function lineOnlyContainsWhitespaceBefore(array $tokens, int $index): bool
    {
        $text = '';

        foreach (array_slice($tokens, 0, $index) as $token) {
            $text .= is_array($token) ? $token[1] : $token;
        }

        return trim(substr($text, strrpos($text, "\n") + 1)) === '';
    }

    private function lineIndent(string $source, int $line): int
    {
        $lines = explode("\n", $source);

        return isset($lines[$line - 1]) ? strlen($lines[$line - 1]) - strlen(ltrim($lines[$line - 1], " \t")) : 0;
    }

    private function error(string $file, int $line, string $message): void
    {
        $this->errors[] = sprintf('%s:%d: %s', $this->relative($file), $line, $message);
    }

    private function relative(string $file): string
    {
        return ltrim(str_replace(getcwd() . '/', '', $file), './');
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit((new PerCs31Verifier())->run(dirname(__DIR__, 2)));
}
