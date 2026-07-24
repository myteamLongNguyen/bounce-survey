import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';

export default defineConfig({
  plugins: [react()],
  build: {
    outDir: 'build',
    emptyOutDir: true,
    // Single CSS file rather than injected chunks — WordPress enqueues it separately.
    cssCodeSplit: false,
    rollupOptions: {
      // Absolute path. A bare relative path resolves inconsistently on Windows.
      // This also means the build ignores index.html, which is dev-only.
      input: fileURLToPath(new URL('./src/main.jsx', import.meta.url)),
      output: {
        // IIFE, not ES modules. wp_enqueue_script outputs a classic <script> tag,
        // so an ES module build would fail silently until the tag is patched.
        format: 'iife',
        entryFileNames: 'survey.js',
        assetFileNames: 'survey.[ext]',
      },
    },
  },
});
