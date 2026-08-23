import js from '@eslint/js';
import pluginVue from 'eslint-plugin-vue';
import vueTsEslintConfig from '@vue/eslint-config-typescript';
import eslintConfigPrettier from 'eslint-config-prettier';

// Section 42 (Phase 0B): added now that real authenticated Vue UI
// exists. ESLint owns correctness/quality rules; Prettier owns
// formatting; eslint-config-prettier disables the small set of ESLint
// stylistic rules that would otherwise conflict with Prettier's
// output, so the two never fight over the same line.
export default [
    js.configs.recommended,
    ...pluginVue.configs['flat/recommended'],
    ...vueTsEslintConfig(),
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
