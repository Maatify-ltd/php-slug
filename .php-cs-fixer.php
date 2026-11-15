<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$directories = array_values(array_filter(
    [__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/examples', __DIR__ . '/tools'],
    static fn(string $directory): bool => is_dir($directory),
));

return (new Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PER-CS3x0' => true,
        // PER-CS 3.1 requires a multiline array's opening bracket to stay with
        // the preceding expression, superseding PER-CS 3.0's call wrapping.
        'method_argument_space' => ['on_multiline' => 'ignore'],
        // PER-CS 3.1 permits any clear fall-through comment. The supplemental verifier checks intent.
        'no_break_comment' => false,
        'declare_strict_types' => true,
    ])
    ->setFinder(
        Finder::create()
            ->in($directories)
            ->append([__FILE__]),
    );
