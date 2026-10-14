import js from '@eslint/js';
import pluginVue from 'eslint-plugin-vue';
import tseslint from 'typescript-eslint';
import vueParser from 'vue-eslint-parser';
import eslintConfigPrettier from 'eslint-config-prettier';
import pluginVueA11y from 'eslint-plugin-vuejs-accessibility';

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

// SWC.1 (ADR 0073 section 8.2): the SWC accessibility baseline, linted.
// Scoped to the files SWC creates or materially changes -- enabling it
// repository-wide reported 800 existing findings in 162 files (2026-10-14),
// a remediation programme SWC is not (OD-SWC-1). Each SWC slice adds the
// files it builds to SWC_ACCESSIBILITY_FILES. `label-has-for` accepts a
// nested OR an id-associated label (the plugin default demands both).
// Inertia's link component is imported as `InertiaLink` in these files:
// the plugin reads a `<Link>` tag as HTML <link>, which takes no ARIA.
const SWC_ACCESSIBILITY_FILES = [
    'resources/js/Layouts/AccountLayout.vue',
    'resources/js/Components/Navigation/**/*.vue',
    'resources/js/Pages/Home.vue',
    'resources/js/Pages/App/Dashboard.vue',
    // SWC.2 (ADR 0073 section 17.5): Academic Structure screens and their components.
    'resources/js/Components/Form/**/*.vue',
    'resources/js/Components/ConfirmDialog.vue',
    'resources/js/Components/SubjectOfferings/**/*.vue',
    'resources/js/Pages/App/SchoolSetup/**/*.vue',
    'resources/js/Pages/App/ElectiveGroups/**/*.vue',
];
const swcAccessibility = pluginVueA11y.configs['flat/recommended'].map((config) => ({
    ...config,
    files: SWC_ACCESSIBILITY_FILES,
}));

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
    ...swcAccessibility,
    {
        files: SWC_ACCESSIBILITY_FILES,
        rules: {
            'vuejs-accessibility/label-has-for': [
                'error',
                { required: { some: ['nesting', 'id'] } },
            ],
        },
    },
    {
        ignores: ['public/build/**', 'vendor/**', 'node_modules/**'],
    },
    {
        rules: {
            'vue/multi-word-component-names': 'off',
        },
    },
];
