<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$finder = Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->name('*.php');

return (new Config())
    ->setRiskyAllowed(true)
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setRules([
        '@PSR12' => true,

        // Modern PHP
        'declare_strict_types' => true,
        'strict_comparison' => true,
        'strict_param' => true,

        // Code Cleanliness
        'array_syntax' => ['syntax' => 'short'],
        'binary_operator_spaces' => ['default' => 'single_space'],
        'no_unused_imports' => true,
        'ordered_imports' => true,
        'single_quote' => true,
        'no_superfluous_phpdoc_tags' => true,
        'no_empty_phpdoc' => true,
        'no_useless_return' => true,
        'no_trailing_whitespace_in_comment' => true,
    ])
    ->setFinder($finder);
