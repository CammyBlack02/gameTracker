// Flat config (ESLint 9). Scoped to js/**/*.js only — the ~1,600 lines of
// inline JS inside PHP pages are un-lintable until phase 4g pulls them out.
// No CI wiring yet: run `npm run lint` locally to see the noise.

const globals = require('globals');

module.exports = [
  {
    ignores: ['node_modules/**', 'js/dist/**'],
  },
  {
    files: ['js/**/*.js'],
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: 'script',
      globals: {
        ...globals.browser,
      },
    },
    rules: {
      'no-console': 'warn',
      'no-var': 'error',
      'prefer-const': 'warn',
      'no-unused-vars': 'warn',
    },
  },
];
