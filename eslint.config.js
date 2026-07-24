import js from '@eslint/js'
import globals from 'globals'

export default [
  {
    ignores: ['src/resources/axe/**', '**/dist/**'],
  },
  js.configs.recommended,
  {
    files: ['src/resources/js/**/*.js'],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'script',
      globals: {
        ...globals.browser,
        Craft: 'readonly',
        Garnish: 'readonly',
        ResizeObserver: 'readonly',
        AccessibilityAuditShared: 'readonly',
        $: 'readonly',
        jQuery: 'readonly',
        axe: 'readonly',
      },
    },
    rules: {
      'no-unused-vars': ['error', { args: 'none', caughtErrors: 'none' }],
      'no-empty': ['error', { allowEmptyCatch: true }],
    },
  },
]
