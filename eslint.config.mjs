import js from '@eslint/js';
import globals from 'globals';

export default [
    {
        ignores: [
            'node_modules/**',
            'oc-includes/assets/**',
            '**/*.min.js',
            '**/*.min.js.map',
        ],
    },
    js.configs.recommended,
    {
        languageOptions: {
            ecmaVersion: 2021,
            sourceType: 'script',
            globals: {
                ...globals.browser,
                Sortable: 'readonly',
            },
        },
    },
];
