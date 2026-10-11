import js from '@eslint/js';
import pluginVue from 'eslint-plugin-vue';
import tseslint from 'typescript-eslint';
import vueParser from 'vue-eslint-parser';
import eslintConfigPrettier from 'eslint-config-prettier';

// E16-R1 (2026-10-11): the TypeScript + Vue setup that
// `@vue/eslint-config-typescript`'s default `createConfig()` produced,
// written out directly with the same two packages it wrapped
// (`typescript-eslint`, `vue-eslint-parser`). The wrapper scanned the
// whole project with fast-glob -> micromatch -> braces, and braces <=3.0.3
// carries an unpatched High advisory (GHSA-vfj7-8cjw-p6xm) the release
// policy cannot except; with no type-aware rules that scan's result was
// unused. The resolved per-file configuration is unchanged (verified with
// `eslint --print-config` before and after).
const vueTypeScript = [
    // typescript-eslint "recommended", applied to .vue wherever it applies to .ts.
    ...tseslint.configs.recommended.map((config) =>
        config.files?.includes('**/*.ts')
            ? { ...config, files: [...config.files, '**/*.vue'] }
            : config,
    ),
    ...pluginVue.configs['flat/base'],
    {
        name: '@vue/typescript/setup',
        files: ['*.vue', '**/*.vue'],
        languageOptions: {
            parser: vueParser,
            parserOptions: {
                parser: { js: 'espree', jsx: 'espree', ts: tseslint.parser, tsx: tseslint.parser },
                ecmaVersion: 2024,
                ecmaFeatures: { jsx: false },
                extraFileExtensions: ['.vue'],
            },
        },
        rules: {
            'vue/block-lang': ['error', { script: { lang: ['ts'], allowNoLang: false } }],
        },
    },
];

// Section 42 (Phase 0B): added now that real authenticated Vue UI
// exists. ESLint owns correctness/quality rules; Prettier owns
// formatting; eslint-config-prettier disables the small set of ESLint
// stylistic rules that would otherwise conflict with Prettier's
// output, so the two never fight over the same line.
export default [
    js.configs.recommended,
    ...pluginVue.configs['flat/recommended'],
    ...vueTypeScript,
    eslintConfigPrettier,
    {
        ignores: ['public/build/**', 'vendor/**', 'node_modules/**'],
    },
    {
        rules: {
            'vue/multi-word-component-names': 'off',
        },
    },
];
