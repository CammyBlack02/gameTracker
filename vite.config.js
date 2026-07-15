// Vite is scoped to the sub-views being migrated off classic <script> loading.
// Phase 4e (this PR): only spin-wheel. Everything else stays as classic scripts.
// Add another entry here when the next sub-view converts.
//
// Output goes to js/dist/ with content-hashed filenames. The vite_asset() helper
// in includes/vite.php reads manifest.json to resolve the hashed URL at render time.

import { defineConfig } from 'vite';
import { resolve } from 'node:path';

export default defineConfig({
  root: '.',
  base: '/js/dist/',
  build: {
    outDir: 'js/dist',
    emptyOutDir: true,
    manifest: 'manifest.json',
    // Keep bundles readable during rollout — small footprint, easier post-mortem.
    // Flip to true once we trust the pipeline.
    minify: false,
    rollupOptions: {
      input: {
        'spin-wheel': resolve(__dirname, 'js/spin-wheel.js'),
      },
      output: {
        entryFileNames: 'assets/[name]-[hash].js',
        chunkFileNames: 'assets/[name]-[hash].js',
        assetFileNames: 'assets/[name]-[hash][extname]',
      },
    },
  },
});
