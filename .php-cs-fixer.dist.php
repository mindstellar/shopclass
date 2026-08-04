<?php

/*
 * Formatting rules for Shopclass PHP sources. Run with the pinned PHAR:
 *   composer cs:check   (dry-run, non-mutating — what CI enforces)
 *   composer cs:fix     (apply)
 *
 * Deliberately non-risky: only whitespace, structure, and import hygiene are
 * touched, never anything that could change runtime behaviour. Generated and
 * vendored trees are excluded because they are not ours to reformat.
 */

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->exclude([
        'oc-includes/vendor',
        'oc-includes/assets',
        'oc-content',
        'oc-includes/osclass/gui',
        'node_modules',
        'tools',
    ])
    ->notName('*.min.php')
    ->notPath('config.php')
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12'                      => true,
        'no_unused_imports'           => true,
        'ordered_imports'             => ['sort_algorithm' => 'alpha'],
        'no_trailing_whitespace'      => true,
        'no_whitespace_in_blank_line' => true,
        'single_blank_line_at_eof'    => true,
        'no_extra_blank_lines'        => true,
    ])
    ->setFinder($finder)
    ->setLineEnding("\n");
