import js from '@eslint/js';

export default [
    js.configs.recommended,
    {
        ignores: [
            'assets/vendor/**',
            'public/build/**',
            'node_modules/**',
            'var/**',
            'vendor/**',
        ],
    },
    {
        files: ['assets/**/*.js'],
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'module',
            globals: {
                // Browser globals
                window: 'readonly',
                document: 'readonly',
                console: 'readonly',
                fetch: 'readonly',
                btoa: 'readonly',
                atob: 'readonly',
                Event: 'readonly',
                CustomEvent: 'readonly',
                setTimeout: 'readonly',
                setInterval: 'readonly',
                clearTimeout: 'readonly',
                clearInterval: 'readonly',
                confirm: 'readonly',
                alert: 'readonly',
                prompt: 'readonly',
                FileReader: 'readonly',
                getComputedStyle: 'readonly',
                URL: 'readonly',
                URLSearchParams: 'readonly',
                // Stimulus (Symfony UX)
                Stimulus: 'readonly',
            },
        },
        rules: {
            'no-unused-vars': ['warn', {argsIgnorePattern: '^_'}],
            'no-console': 'off',
            'semi': ['error', 'always'],
            'quotes': ['error', 'single'],
        },
    },
];
