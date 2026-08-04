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
        // Reindenting multi-line PHP embedded in inline-HTML templates is not
        // deterministic across PHP runtimes (the fixer tokenises with the host
        // PHP), which made the same files format differently on 8.2 vs 8.3. Pin
        // these two to a stable behaviour so any supported PHP agrees.
        'method_argument_space'       => ['on_multiline' => 'ignore'],
        'statement_indentation'       => false,
        'no_unused_imports'           => true,
        'ordered_imports'             => ['sort_algorithm' => 'alpha'],
        'no_trailing_whitespace'      => true,
        'no_whitespace_in_blank_line' => true,
        'single_blank_line_at_eof'    => true,
        'no_extra_blank_lines'        => true,
    ])
    ->setFinder($finder)
    ->setLineEnding("\n");
