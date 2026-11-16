<?php

declare(strict_types=1);

require __DIR__ . '/verify-per-cs31.php';

$root = sys_get_temp_dir() . '/php-slug-per-cs31-regression-' . bin2hex(random_bytes(16));
if (!mkdir($root, 0700)) {
    throw new RuntimeException('Unable to create the PER-CS regression fixture directory.');
}

$ownedFiles = [];
register_shutdown_function(static function () use ($root, &$ownedFiles): void {
    foreach (array_keys($ownedFiles) as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($root);
});

$writeFixture = static function (string $file, string $contents) use (&$ownedFiles): void {
    $ownedFiles[$file] = true;
    if (file_put_contents($file, $contents) === false) {
        throw new RuntimeException('Unable to write PER-CS regression fixture: ' . $file);
    }
};

$verifier = new PerCs31Verifier();
$pass = 0;
$fail = 0;

$cases = [
    'switch ordinary case' => [true, <<<'PHP'
<?php
switch ($value) {
    case 1:
        echo 'one';
        break;
}
PHP],
    'switch final terminating case' => [true, <<<'PHP'
<?php
switch ($value) {
    default:
        return;
}
PHP],
    'switch deliberate fall-through' => [true, <<<'PHP'
<?php
switch ($value) {
    case 1:
        echo 'one';
        // no break
    case 2:
        echo 'two';
        break;
}
PHP],
    'switch clear fall-through wording' => [true, <<<'PHP'
<?php
switch ($value) {
    case 1:
        echo 'one';
        // Falls through intentionally.
    case 2:
        echo 'two';
        break;
}
PHP],
    'switch rejects ambiguous fall-through question' => [false, <<<'PHP'
<?php
switch ($value) {
    case 1:
        echo 'one';
        // Does this case fall through?
    case 2:
        break;
}
PHP],
    'switch rejects opposition in a multiline comment' => [false, <<<'PHP'
<?php
switch ($value) {
    case 1:
        echo 'one';
        // Deliberate fall-through.
        /* Do not enter
         * the next case.
         */
    case 2:
        break;
}
PHP],
    'switch rejects opposition separated from affirmative comment by code' => [false, <<<'PHP'
<?php
switch ($value) {
    case 1:
        // Do not enter the next case.
        echo 'one';
        // Deliberate fall-through.
    case 2:
        break;
}
PHP],
    'switch rejects nested fall-through comment' => [false, <<<'PHP'
<?php
switch ($value) {
    case 1:
        if ($enabled) {
            echo 'one';
            // No break
        }
    case 2:
        break;
}
PHP],
    'switch multiline condition' => [true, <<<'PHP'
<?php
switch (true) {
    case (
        $left === 1
        && $right === 2
    ):
        echo 'match';
        break;
}
PHP],
    'switch nested blocks' => [true, <<<'PHP'
<?php
switch ($value) {
    case 1:
        if ($enabled) {
            echo 'enabled';
        }
        $worker->run();
        break;
}
PHP],
    'clone SHOULD is non-failing' => [true, '<?php $copy = clone $object;'],
    'reject wrapped case body' => [false, <<<'PHP'
<?php
switch ($value) {
    case 1: {
        echo 'one';
        break;
    }
}
PHP],
    'reject final case without termination' => [false, <<<'PHP'
<?php
switch ($value) {
    default:
        echo 'default';
}
PHP],
    'reject undocumented fall-through' => [false, <<<'PHP'
<?php
switch ($value) {
    case 1:
        echo 'one';
    case 2:
        break;
}
PHP],
    'reject malformed multiline condition' => [false, <<<'PHP'
<?php
switch ($value) {
    case $left === 1
        && $right === 2:
        break;
}
PHP],
    'reject nested conditional termination' => [false, <<<'PHP'
<?php
switch ($value) {
    case 1:
        if ($enabled) {
            return;
        }
        echo 'still reaches case end';
}
PHP],
    'reject termination followed by statement' => [false, <<<'PHP'
<?php
switch ($value) {
    case 1:
        break;
        echo 'unreachable but structurally present';
}
PHP],
    'reject nested loop break as case termination' => [false, <<<'PHP'
<?php
switch ($value) {
    case 1:
        while ($running) {
            break;
        }
}
PHP],
    'direct final return pass' => [true, <<<'PHP'
<?php
switch ($value) {
    case 1:
        return;
}
PHP],
    'direct return value pass' => [true, "<?php\nswitch (\$value) { case 1: return \$value; }\n"],
    'direct return call pass' => [true, "<?php\nswitch (\$value) { case 1: return foo(); }\n"],
    'direct throw variable pass' => [true, "<?php\nswitch (\$value) { case 1: throw \$exception; }\n"],
    'direct throw expression pass' => [true, "<?php\nswitch (\$value) { case 1: throw new RuntimeException(); }\n"],
    'direct break pass' => [true, "<?php\nswitch (\$value) { case 1: break; }\n"],
    'direct break level pass' => [true, "<?php\nswitch (\$value) { case 1: break 2; }\n"],
    'direct continue pass' => [true, "<?php\nswitch (\$value) { case 1: continue; }\n"],
    'direct continue level pass' => [true, "<?php\nswitch (\$value) { case 1: continue 2; }\n"],
    'direct goto pass' => [true, "<?php\nswitch (\$value) { case 1: goto label; }\n"],
    'direct exit pass' => [true, "<?php\nswitch (\$value) { case 1: exit; }\n"],
    'direct exit call pass' => [true, "<?php\nswitch (\$value) { case 1: exit(1); }\n"],
    'anonymous class attributes pass' => [true, <<<'PHP'
<?php
$value = new
    #[Example]
    class {};
PHP],
    'multiple anonymous class attributes pass' => [true, <<<'PHP'
<?php
$value = new
    #[First]
    #[Second]
    class {};
PHP],
    'reject attribute on new line' => [false, <<<'PHP'
<?php
$value = new #[Example] class {};
PHP],
    'reject class on attribute line' => [false, <<<'PHP'
<?php
$value = new
    #[Example] class {};
PHP],
    'reject attribute indentation' => [false, <<<'PHP'
<?php
$value = new
  #[Example]
class {};
PHP],
    'reject inconsistent multiple attribute indentation' => [false, <<<'PHP'
<?php
$value = new
    #[First]
  #[Second]
    class {};
PHP],
    'reject class indentation different from attributes' => [false, <<<'PHP'
<?php
$value = new
    #[First]
        class {};
PHP],
    'enum private and public constants pass' => [true, <<<'PHP'
<?php
enum Sample {
    private const PRIVATE_VALUE = 1;
    public const PUBLIC_VALUE = 2;
}
PHP],
    'reject enum protected constant' => [false, <<<'PHP'
<?php
enum Sample {
    protected const VALUE = 1;
}
PHP],
    'nested protected constant is not enum-level' => [true, <<<'PHP'
<?php
enum Sample {
    public function build(): object {
        return new class {
            protected const VALUE = 1;
        };
    }
}
PHP],
    'single multiline array argument pass' => [true, "<?php\nconsume([\n    1,\n]);\n"],
    'single multiline closure argument pass' => [true, "<?php\nconsume(function (): void {\n});\n"],
    'reject split scalar argument list first inline' => [false, "<?php\nconsume(\$first,\n    \$second);\n"],
    'reject split scalar arguments on one line' => [false, "<?php\nconsume(\n    \$first, \$second\n);\n"],
    'reject split scalar arguments after nested value' => [false, "<?php\nconsume([\n    1,\n], \$second, \$third\n);\n"],
    'split call exact indentation pass' => [true, "<?php\nconsume(\n    \$first,\n    \$second,\n);\n"],
    'reject split call under-indentation' => [false, "<?php\nconsume(\n  \$first,\n    \$second,\n);\n"],
    'reject split call over-indentation' => [false, "<?php\nconsume(\n      \$first,\n    \$second,\n);\n"],
    'reject split call inconsistent indentation' => [false, "<?php\nconsume(\n    \$first,\n      \$second,\n);\n"],
    'split named function parameters exact indentation pass' => [true, <<<'PHP'
<?php
function build(
    $first,
    $second,
): void {}
PHP],
    'reject named function parameter indentation' => [false, <<<'PHP'
<?php
function build(
  $first,
    $second,
): void {}
PHP],
    'split named by-reference function parameters pass' => [true, <<<'PHP'
<?php
function &build(
    $first,
    $second,
) {
}
PHP],
    'reject named by-reference function closing parenthesis placement' => [false, <<<'PHP'
<?php
function &build(
    $first,
    $second) {
}
PHP],
    'reject named by-reference function parameter indentation' => [false, <<<'PHP'
<?php
function &build(
  $first,
    $second,
) {
}
PHP],
    'split named method parameters exact indentation pass' => [true, <<<'PHP'
<?php
final class Builder
{
    public function build(
        $first,
        $second,
    ): void {}
}
PHP],
    'split named by-reference method parameters pass' => [true, <<<'PHP'
<?php
final class Builder
{
    public function &build(
        $first,
        $second,
    ) {
    }
}
PHP],
    'reject named by-reference method closing parenthesis placement' => [false, <<<'PHP'
<?php
final class Builder
{
    public function &build(
        $first,
        $second) {
    }
}
PHP],
    'split anonymous closure parameters pass' => [true, <<<'PHP'
<?php
$closure = function (
    $first,
    $second,
) {};
PHP],
    'reject anonymous closure parameter split' => [false, "<?php\n\$closure = function (\$first,\n    \$second,\n) {};\n"],
    'reject anonymous closure parameters on one line' => [false, "<?php\n\$closure = function (\n    \$first, \$second\n) {};\n"],
    'closure use list exact indentation pass' => [true, <<<'PHP'
<?php
$closure = function () use (
    $first,
    $second,
) {};
PHP],
    'reject closure use first variable inline' => [false, "<?php\n\$closure = function () use (\$first,\n    \$second,\n) {};\n"],
    'reject closure use variables on one line' => [false, "<?php\n\$closure = function () use (\n    \$first, \$second,\n) {};\n"],
    'reject closure use under-indentation' => [false, "<?php\n\$closure = function () use (\n  \$first,\n    \$second,\n) {};\n"],
    'reject closure use over-indentation' => [false, "<?php\n\$closure = function () use (\n      \$first,\n    \$second,\n) {};\n"],
    'reject closure use inconsistent indentation' => [false, "<?php\n\$closure = function () use (\n    \$first,\n      \$second,\n) {};\n"],
    'by-reference closure parameters pass' => [true, <<<'PHP'
<?php
$closure = function &(
    $first,
    $second,
) {};
PHP],
    'reject by-reference closure first parameter inline' => [false, "<?php\n\$closure = function &(\$first,\n    \$second,\n) {};\n"],
    'reject by-reference closure parameter indentation' => [false, "<?php\n\$closure = function &(\n  \$first,\n    \$second,\n) {};\n"],
    'reject by-reference closure closing parenthesis placement' => [false, "<?php\n\$closure = function &(\n    \$first,\n    \$second) {};\n"],
    'by-reference arrow parameters pass' => [true, <<<'PHP'
<?php
$arrow = fn&(
    $first,
    $second,
) => $first;
PHP],
    'by-reference closure use list pass' => [true, <<<'PHP'
<?php
$closure = function &() use (
    &$first,
    &$second,
) {};
PHP],
    'anonymous class constructor arguments pass' => [true, <<<'PHP'
<?php
$instance = new class (
    $first,
    $second,
) {};
PHP],
    'reject anonymous constructor first argument inline' => [false, "<?php\n\$instance = new class (\$first,\n    \$second,\n) {};\n"],
    'reject anonymous constructor arguments on one line' => [false, "<?php\n\$instance = new class (\n    \$first, \$second,\n) {};\n"],
    'reject anonymous constructor under-indentation' => [false, "<?php\n\$instance = new class (\n  \$first,\n    \$second,\n) {};\n"],
    'reject anonymous constructor over-indentation' => [false, "<?php\n\$instance = new class (\n      \$first,\n    \$second,\n) {};\n"],
    'reject anonymous constructor inconsistent indentation' => [false, "<?php\n\$instance = new class (\n    \$first,\n      \$second,\n) {};\n"],
    'attributed anonymous constructor pass' => [true, <<<'PHP'
<?php
$instance = new
    #[Example]
    class (
        $first,
        $second,
    ) {};
PHP],
    'reject attributed constructor first argument inline' => [false, "<?php\n\$instance = new\n    #[Example]\n    class (\$first,\n        \$second,\n    ) {};\n"],
    'reject attributed constructor arguments on one line' => [false, "<?php\n\$instance = new\n    #[Example]\n    class (\n        \$first, \$second,\n    ) {};\n"],
    'reject attributed constructor under-indentation' => [false, "<?php\n\$instance = new\n    #[Example]\n    class (\n      \$first,\n        \$second,\n    ) {};\n"],
    'reject attributed constructor over-indentation' => [false, "<?php\n\$instance = new\n    #[Example]\n    class (\n          \$first,\n        \$second,\n    ) {};\n"],
    'reject attributed constructor inconsistent indentation' => [false, "<?php\n\$instance = new\n    #[Example]\n    class (\n        \$first,\n          \$second,\n    ) {};\n"],
    'reject named function closing parenthesis placement' => [false, "<?php\nfunction build(\n    \$first,\n    \$second): void {}\n"],
    'reject closure closing parenthesis placement' => [false, "<?php\n\$closure = function (\n    \$first,\n    \$second) {};\n"],
    'reject closure use closing parenthesis placement' => [false, "<?php\n\$closure = function () use (\n    \$first,\n    \$second) {};\n"],
    'attributed parameters pass' => [true, <<<'PHP'
<?php
function build(
    #[Example]
    $first,
    $second,
): void {}
PHP],
    'reject attributed parameter same-line second argument' => [false, "<?php\nfunction build(\n    #[Example] \$first, \$second,\n): void {}\n"],
    'reject attributed parameter next indentation' => [false, "<?php\nfunction build(\n    #[Example]\n    \$first,\n      \$second,\n): void {}\n"],
    'multiple attributed parameters pass' => [true, <<<'PHP'
<?php
function build(
    #[First]
    $first,
    #[Second]
    $second,
): void {}
PHP],
    'single multiline match argument pass' => [true, <<<'PHP'
<?php
consume(match ($value) {
    1 => 'one',
    2 => 'two',
});
PHP],
    'nested comma structure argument pass' => [true, <<<'PHP'
<?php
consume([
    'nested' => [1, 2],
]);
PHP],
    'parenthesized expression is not an argument list' => [true, "<?php\n\$value = (\$first\n    + \$second);\n"],
    'single-line ternary case pass' => [true, <<<'PHP'
<?php
switch ($value) {
    case $condition ? 1 : 2:
        break;
}
PHP],
    'nested ternary case pass' => [true, <<<'PHP'
<?php
switch ($value) {
    case ($first ? ($second ? 1 : 2) : 3):
        break;
}
PHP],
    'reject assignment array bracket' => [false, "<?php\n\$value =\n[\n    1,\n];\n"],
    'reject return array bracket' => [false, "<?php\nreturn\n[\n    1,\n];\n"],
    'reject argument array bracket' => [false, "<?php\nconsume(\n[\n    1,\n]\n);\n"],
    'reject nested array bracket' => [false, "<?php\n\$value = [\n[\n    1,\n],\n];\n"],
    'reject arrow array bracket' => [false, "<?php\n\$value = fn(): array =>\n[\n    1,\n];\n"],
    'reject conditional array bracket' => [false, "<?php\n\$value = \$condition ?\n[\n    1,\n] : [];\n"],
    'reject cast expression array bracket' => [false, "<?php\n\$value = (array)\n[\n    1,\n];\n"],
    'reject unary expression array bracket' => [false, "<?php\n\$value = !\n[\n    1,\n];\n"],
    'reject yield from array bracket' => [false, <<<'PHP'
<?php
function values(): iterable
{
    yield from
    [
        1,
    ];
}
PHP],
    'reject unpacked argument array bracket' => [false, "<?php\nconsume(...\n[\n    1,\n]);\n"],
    'reject conditional throw expression as case termination' => [false, <<<'PHP'
<?php
switch ($value) {
    case 1:
        $value = $condition ? throw $exception : 1;
}
PHP],
    'reject coalescing exit expression as case termination' => [false, <<<'PHP'
<?php
switch ($value) {
    case 1:
        $value = foo() ?? exit();
}
PHP],
    'array access is not a literal' => [true, "<?php\n\$value = \$items[\n    \$key\n];\n"],
    'attribute syntax is not an array literal' => [true, "<?php\n#[Example]\nclass Sample {}\n"],
    'destructuring syntax is not an array literal' => [true, "<?php\n[\n    \$first,\n    \$second,\n] = \$values;\n"],
    'foreach destructuring is not an array literal' => [true, <<<'PHP'
<?php
foreach ($rows as
[
    $first,
    $second,
]) {
}
PHP],
    'nested destructuring is not an array literal' => [true, <<<'PHP'
<?php
[$first, [$second, $third]] = $values;
PHP],
    'nested foreach destructuring is not an array literal' => [true, <<<'PHP'
<?php
foreach ($rows as
[
    $first,
    [
        $second,
        $third,
    ],
]) {
}
PHP],
    'keyed foreach destructuring is not an array literal' => [true, <<<'PHP'
<?php
foreach ($rows as $key =>
[
    $first,
    $second,
]) {
}
PHP],
    'keyed assignment destructuring is not an array literal' => [true, <<<'PHP'
<?php
[
    'key' =>
    [
        $first,
        $second,
    ],
] = $values;
PHP],
    'keyed nested foreach destructuring is not an array literal' => [true, <<<'PHP'
<?php
foreach ($rows as
[
    'key' =>
    [
        $first,
        $second,
    ],
]) {
}
PHP],
];

foreach ($cases as $name => [$expectedPass, $source]) {
    $file = $root . '/' . sha1($name) . '.php';
    $writeFixture($file, $source);
    $errors = $verifier->verifyFiles([$file]);
    $actualPass = $errors === [];

    if ($actualPass === $expectedPass) {
        $pass++;
        continue;
    }

    $fail++;
    fwrite(STDERR, "FAIL {$name}: " . implode(' | ', $errors) . PHP_EOL);
}

$reviewed = $root . '/reviewed.php';
$approvalFile = $root . '/fallthrough.json';
$commentCases = [
    ['No break: continue to the next case.', null, 3, true],
    ['Deliberate fall-through.', null, 3, true],
    ["Deliberate fall-through.\n        // Do not enter the next case.", null, 3, false],
    ["Do not enter the next case.\n        // Deliberate fall-through.", null, 3, false],
    ["Deliberate fall-through.\n        // Do not enter\n        // the next case.", null, 3, false],
    ["Deliberate fall-through.\n        // Should we enter the next case?", null, 3, false],
    ["Deliberate fall-through.\n        // Break here.", null, 3, false],
    ["Deliberate fall-through.\n        // Stop here before the next case.", null, 3, false],
    ["Deliberate fall-through.\n        // We must stop here before the next case.", null, 3, false],
    ["Deliberate fall-through.\n        // We should stop here before the next case.", null, 3, false],
    ["Deliberate fall-through.\n        // We must immediately stop here before the next case.", null, 3, false],
    ["Deliberate fall-through.\n        // We should stop now before the next case.", null, 3, false],
    ["Deliberate fall-through.\n        // We must now stop here before the next case.", null, 3, false],
    ["Deliberate fall-through.\n        // We now must stop here before the next case.", null, 3, false],
    ["Deliberate fall-through.\n        // We must stop here immediately.", null, 3, false],
    ["Deliberate fall-through.\n        // We must return immediately.", null, 3, false],
    ["Deliberate fall-through.\n        // We must return false.", null, 3, false],
    ["Deliberate fall-through.\n        // This branch must return false.", null, 3, false],
    ["Deliberate fall-through.\n        // Return `\$value ?? null`.", null, 3, false],
    ["Deliberate fall-through.\n        // We must return directly from the current function.", null, 3, false],
    ["Deliberate fall-through.\n        // We must return PHP_INT_MAX - 1.", null, 3, false],
    ["Deliberate fall-through.\n        // Return baseLimit + 1.", null, 3, false],
    ["Deliberate fall-through.\n        // Return baseLimit + monthly.", null, 3, false],
    ["Deliberate fall-through.\n        // Return baseLimit + monthly immediately.", null, 3, false],
    ["Deliberate fall-through.\n        // Return baseLimit + monthly without delay.", null, 3, false],
    ["Deliberate fall-through.\n        // Return `baseLimit + monthly` immediately.", null, 3, false],
    ["Deliberate fall-through.\n        // Return false immediately.", null, 3, false],
    ["Deliberate fall-through.\n        // Return baseLimit + right now.", null, 3, false],
    ["Deliberate fall-through.\n        // Return baseLimit + right, now.", null, 3, false],
    ["Deliberate fall-through.\n        // Return baseLimit + right without delay.", null, 3, false],
    ["Deliberate fall-through.\n        // Return baseLimit + monthly immediately without delay.", null, 3, false],
    ["Deliberate fall-through.\n        // Return false right now.", null, 3, false],
    ["Deliberate fall-through.\n        // Return home immediately.", null, 3, true],
    ["Deliberate fall-through.\n        // Return home (immediately).", null, 3, true],
    ["Deliberate fall-through.\n        // Return on-site (immediately).", null, 3, true],
    ["Deliberate fall-through.\n        // Return on-site(immediately).", null, 3, false],
    ["Deliberate fall-through.\n        // Return `on-site (immediately)`.", null, 3, false],
    ["Deliberate fall-through.\n        // Return on - site (immediately).", null, 3, false],
    ["Deliberate fall-through.\n        // Return home(immediately).", null, 3, false],
    ["Deliberate fall-through.\n        // Return `home (immediately)`.", null, 3, false],
    ["Deliberate fall-through.\n        // Return baseLimit+1.", null, 3, false],
    ["Deliberate fall-through.\n        // Return carry-on.", null, 3, true],
    ["Deliberate fall-through.\n        // Return min - limit.", null, 3, false],
    ["Deliberate fall-through.\n        // Return `min-limit`.", null, 3, false],
    ["Deliberate fall-through.\n        // Return limit << width.", null, 3, false],
    ["Deliberate fall-through.\n        // Return الحد ?? البداية.", null, 3, false],
    ["Deliberate fall-through.\n        // Return some . other.", null, 3, false],
    ["Deliberate fall-through.\n        // Return some . other if the input is invalid.", null, 3, true],
    ["Deliberate fall-through.\n        // Return `baseLimit`.", null, 3, false],
    ["Deliberate fall-through.\n        // Return home.", null, 3, true],
    ["Deliberate fall-through.\n        // Return home and rest.", null, 3, true],
    ["Deliberate fall-through.\n        // Return new books.", null, 3, true],
    ["Deliberate fall-through.\n        // We must return \\PHP_INT_MAX - 1.", null, 3, false],
    ["Deliberate fall-through.\n        // Return Vendor\\Domain\\limit + 1.", null, 3, false],
    ["Deliberate fall-through.\n        // Return namespace\\DEFAULT_LIMIT - 1.", null, 3, false],
    ["Deliberate fall-through.\n        // We must return from the current function to the caller.", null, 3, false],
    ["Deliberate fall-through.\n        // Return from this method directly to its caller.", null, 3, false],
    ["Deliberate fall-through.\n        // We must not return from this method to the caller.", null, 3, true],
    ["Deliberate fall-through.\n        // Return from this method to the caller if the cache is invalid.", null, 3, true],
    ["Deliberate fall-through.\n        // Return PHP_INT_MAX - 1 if the result overflowed.", null, 3, true],
    ["Deliberate fall-through.\n        // Return the book to its owner.", null, 3, true],
    ["Deliberate fall-through.\n        // We must return from the function.", null, 3, false],
    ["Deliberate fall-through.\n        // We should return null here.", null, 3, false],
    ["Deliberate fall-through.\n        // We must return \$result;", null, 3, false],
    ["Deliberate fall-through.\n        // We must return 42.5.", null, 3, false],
    ["Deliberate fall-through.\n        // We must return [\$answer, null];", null, 3, false],
    ["Deliberate fall-through.\n        // We must return factory()->get(\$result, \"a.b\") ?? false.", null, 3, false],
    ["Deliberate fall-through.\n        // We must return compute(\$result).", null, 3, false],
    ["Deliberate fall-through.\n        // We must return directly from this method.", null, 3, false],
    ["Deliberate fall-through.\n        // Output: \"We must return false.\"", null, 3, true, "echo 'We must return false.';"],
    ["Deliberate fall-through.\n        // \"We must return false.\"", null, 3, false, "echo 'We must return false.';"],
    ["Deliberate fall-through.\n        // We must return the book to the library.", null, 3, true],
    ["Deliberate fall-through.\n        // We must return book.", null, 3, true],
    ["Deliberate fall-through.\n        // We must return 42.5 kg of goods.", null, 3, true],
    ["Deliberate fall-through.\n        // We must return \"the book\" to the library.", null, 3, true],
    ["Deliberate fall-through.\n        // The helper will return false.", null, 3, true],
    ["Deliberate fall-through.\n        // We must return false if the data is invalid.", null, 3, true],
    ["Deliberate fall-through.\n        // We must return \$value ? 1 : 2 if the input is invalid.", null, 3, true],
    ["Deliberate fall-through.\n        // We must return \$first . \$last if the name is invalid.", null, 3, true],
    ["Deliberate fall-through.\n        // We, if data is missing, must return false.", null, 3, true],
    ["Deliberate fall-through.\n        // We must not return false.", null, 3, true],
    ["Deliberate fall-through.\n        // We must return immediately; the branch has completed its work.", null, 3, false],
    ["Deliberate fall-through.\n        // We should stop the download now; the next case processes its data.", null, 3, true],
    ["Deliberate fall-through.\n        // We, of course, must stop here before the next case.", null, 3, false],
    ["Deliberate fall-through.\n        // Return immediately.", null, 3, false],
    ["Deliberate fall-through.\n        // Break.", null, 3, false],
    ["Deliberate fall-through.\n        // We must return.", null, 3, false],
    ["Deliberate fall-through.\n        // We (by the existing protocol) must return immediately.", null, 3, false],
    ["Deliberate fall-through.\n        // We (of course) must return immediately.", null, 3, false],
    ["Deliberate fall-through.\n        // We, if loading fails, must return.", null, 3, true],
    ["Deliberate fall-through.\n        // We must (under the current protocol) return immediately.", null, 3, false],
    ["Deliberate fall-through.\n        // We must stop (without delay) before the next case.", null, 3, false],
    ["Deliberate fall-through.\n        // We must stop, of course, before the next case.", null, 3, false],
    ["Deliberate fall-through.\n        // Of course, we must return immediately.", null, 3, false],
    ["Deliberate fall-through.\n        // We must not immediately stop.", null, 3, true],
    ["Deliberate fall-through.\n        // We, of course, must not return immediately.", null, 3, true],
    ["Deliberate fall-through.\n        // We must (not immediately) stop here before the next case.", null, 3, true],
    ["Deliberate fall-through.\n        // We must stop (the download) before the next case processes its data.", null, 3, true],
    ["Deliberate fall-through.\n        // This case should stop the download next.", null, 3, true],
    ["Deliberate fall-through.\n        // Output: \"We must return immediately.\"", null, 3, true, "echo 'We must return immediately.';"],
    ["Deliberate fall-through.\n        // \"We must return immediately\"", null, 3, false, "echo 'We must return immediately';"],
    ["Deliberate fall-through.\n        // \"Must we return immediately?\"", null, 3, false, "echo 'Must we return immediately?';"],
    ["Deliberate fall-through.\n        // We must promptly stop before reaching the following case.", null, 3, false],
    ["Deliberate fall-through.\n        // We really must promptly stop right here before proceeding to the following case.", null, 3, false],
    ["Deliberate fall-through.\n        // Please stop right away before going into the next case.", null, 3, false],
    ["Deliberate fall-through.\n        // We must, without delay, stop before the next case.", null, 3, false],
    ["Deliberate fall-through.\n        // Should we promptly stop before the next case?", null, 3, false],
    ["Deliberate fall-through.\n        // We must not immediately stop here before the next case.", null, 3, true],
    ["Deliberate fall-through.\n        // We should absolutely not stop now before the next case.", null, 3, true],
    ["Deliberate fall-through.\n        // The helper should promptly stop fetching pages before the next case reads the results.", null, 3, true],
    ["Deliberate fall-through.\n        // We should promptly stop the download before the next case processes its data.", null, 3, true],
    ["Deliberate fall-through.\n        // Output: \"We must immediately stop here before the next case.\"", null, 3, true, "echo 'We must immediately stop here before the next case.';"],
    ["Deliberate fall-through.\n        // \"We must immediately stop here before the next case.\"", null, 3, false, "echo 'We must immediately stop here before the next case.';"],
    ["Deliberate fall-through.\n        // Execution needs to stop here before reaching the following case.", null, 3, false],
    ["Deliberate fall-through.\n        // We must not stop here before the next case.", null, 3, true],
    ["Deliberate fall-through.\n        // The displayed message is \"It's displayed. Do not enter the next case.\"", null, 3, true, "echo 'It\\'s displayed. Do not enter the next case.';"],
    ["Deliberate fall-through.\n        // Output: \"Path C:\\logs. Do not enter the next case.\"", null, 3, true, "echo 'Path C:\\\\logs. Do not enter the next case.';"],
    ["Deliberate fall-through.\n        // The displayed message is 'Do not enter the next case named \"B\".'", null, 3, true, "echo \"Do not enter the next case named \\\"B\\\".\";"],
    ["Deliberate fall-through.\n        // Output: \"It's displayed. Do not enter the next case.\"", null, 3, true, "echo \"It\\047s displayed. Do not enter the next case.\";"],
    ["Deliberate fall-through.\n        // Output: \"It's displayed. Do not enter the next case.\"", null, 3, true, "echo \"It\\x27s displayed. Do not enter the next case.\";"],
    ["Deliberate fall-through.\n        // Output: \"A. Do not enter the next case.\"", null, 3, true, "echo \"\\u{41}. Do not enter the next case.\";"],
    ["Deliberate fall-through.\n        // Output: \"0. Do not enter the next case.\"", null, 3, true, "echo \"\\u{30}. Do not enter the next case.\";"],
    ["Deliberate fall-through.\n        // Output: \"Path C:\\logs. Do not enter the next case.\"", null, 3, true, "echo \"Path C:\\logs. Do not enter the next case.\";"],
    ["Deliberate fall-through.\n        // \"It's displayed. Do not enter the next case.\"", null, 3, false, "echo 'It\\'s displayed. Do not enter the next case.';"],
    ["Deliberate fall-through.\n        // \"Does this case continue?\"", null, 3, false, "echo \"Does this case continue\\077\";"],
    ["Deliberate fall-through.\n        // Return here before entering the following case.", null, 3, false],
    ["Deliberate fall-through.\n        // A helper can return here before the next case reads its data.", null, 3, true],
    ["Deliberate fall-through.\n        // The displayed message is \"Do not enter the next case.\"", null, 3, true, "echo 'Do not enter the next case.';"],
    ["Deliberate fall-through.\n        // The displayed message is \"Do not enter the next case.\"", null, 3, false],
    ["Deliberate fall-through.\n        // \"Do not enter the next case.\"", null, 3, false, "echo 'Do not enter the next case.';"],
    ["Deliberate fall-through.\n        // \"Stop here before the next case.\"", null, 3, false, "echo 'Stop here before the next case.';"],
    ["Deliberate fall-through.\n        // \"Does this case continue?\"", null, 3, false, "echo 'Does this case continue?';"],
    ["Deliberate fall-through.\n        // Output: “Does this case continue?”", null, 3, true, "echo 'Does this case continue?';"],
    ["Deliberate fall-through.\n        // The displayed message is \"Do not enter the next case.\" Stop here before the next case.", null, 3, false, "echo 'Do not enter the next case.';"],
    ["Deliberate fall-through.\n        // Add a break.", null, 3, false],
    ["Deliberate fall-through.\n        // Should we break?", null, 3, false],
    ["Deliberate fall-through.\n        // Which report contains the total?", null, 3, true],
    ["Which report contains the total?\n        // Deliberate fall-through.", null, 3, true],
    ["Deliberate fall-through.\n        // What data does the next case need?", null, 3, true],
    ["Deliberate fall-through.\n        // Should the next case execute?", null, 3, false],
    ["Deliberate fall-through.\n        // The helper may return here with an empty payload.", null, 3, true],
    ['No break here; the next case handles the result.', 'case 1:', 3, true],
    ['We intentionally omit the break so the following case executes.', null, 3, true],
    ['No break: the next case must not execute.', null, 3, false],
    ['No break: do not enter the next case.', null, 3, false],
    ['No break: maybe use the next case.', null, 3, false],
    ['Fall through to prevent the next case from running.', null, 3, false],
    ['We intentionally omit the break so the next case is skipped.', null, 3, false],
    ['No break: the next case handles the result.', null, 3, false],
    ['No break: the next case handles the result.', 'case 1:', 3, true],
    ['No break: the next case must not execute.', 'case 1:', 3, false],
    ['No break: do not enter the next case.', 'case 1:', 3, false],
    ['No break: maybe use the next case.', 'case 1:', 3, false],
    ['We intentionally omit the break so the next case is skipped.', 'case 1:', 3, false],
    ['Fall through to prevent the next case from running.', 'case 1:', 3, false],
    ['The break is left out on purpose so the next case handles the “?” token.', 'case 1:', 3, true],
    ['نتعمّد تجاوز break حتى تكمل الحالة التالية.', 'case 1:', 3, true],
    ['"Does this case continue?"', 'case 1:', 3, false],
    ['هل نترك break لكي تنتقل الحالة التالية؟', 'case 1:', 3, false],
    ['The break is left out on purpose so the next case handles the “?” token.', 'case 2:', 3, false],
    ['The break is left out on purpose so the next case handles the “?” token.', 'case 1:', 4, false],
];
foreach ($commentCases as $case) {
    [$comment, $label, $line, $expectedPass] = $case;
    $statement = $case[4] ?? "echo 'one';";
    $source = <<<PHP
<?php
switch (\$value) {
    case 1:
        {$statement}
        // {$comment}
    case 2:
        break;
}
PHP;
    $writeFixture($reviewed, $source);
    if ($label !== null) {
        $writeFixture($approvalFile, json_encode([[
            'file' => ltrim($reviewed, './'),
            'line' => $line,
            'case' => $label,
            'comment' => $comment,
        ]], JSON_THROW_ON_ERROR));
    }
    $errors = $verifier->verifyFiles([$reviewed], $label === null ? null : $approvalFile);
    if (($errors === []) === $expectedPass) {
        $pass++;
        continue;
    }

    $fail++;
    fwrite(STDERR, sprintf("FAIL fall-through case %s at line %d: %s%s", $label ?? 'without approval', $line, $comment, PHP_EOL));
}

$invalid = $root . '/invalid.php';
$writeFixture($invalid, '<?php function broken( {');
$invalidErrors = $verifier->verifyFiles([$invalid]);
if ($invalidErrors !== []) {
    $pass++;
} else {
    $fail++;
    fwrite(STDERR, "FAIL invalid source was accepted" . PHP_EOL);
}

$unreadableErrors = $verifier->verifyFiles([$root . '/does-not-exist.php']);
if ($unreadableErrors !== []) {
    $pass++;
} else {
    $fail++;
    fwrite(STDERR, "FAIL unreadable source was accepted" . PHP_EOL);
}

printf("PER-CS 3.1 regression cases: %d PASS, %d FAIL.%s", $pass, $fail, PHP_EOL);
exit($fail === 0 ? 0 : 1);
