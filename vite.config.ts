import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'path';

export default defineConfig({
  plugins: [
    react({
      // Classic runtime so JSX compiles to React.createElement() — this lets
      // Rollup correctly trace the single 'react' external rather than also
      // bundling 'react/jsx-runtime', which has no WordPress global equivalent.
      jsxRuntime: 'classic',
    }),
  ],
  build: {
    outDir: 'build',
    rollupOptions: {
      input: resolve( __dirname, 'src/index.tsx' ),
      // Tell Rollup these are provided by WordPress — do NOT bundle them.
      // WordPress loads React via the 'wp-element' script handle, which sets
      // window.React and window.ReactDOM before any plugin script runs.
      external: [ 'react', 'react-dom' ],
      output: {
        // iife wraps the bundle in a self-executing function and replaces the
        // external imports with the global variables declared below.
        // WordPress loads scripts as plain <script> tags, not ES modules, so
        // 'es' format (the Vite default) leaves bare import statements that the
        // browser cannot resolve. 'iife' produces a single self-contained file.
        format: 'iife',
        name: 'WPPilot',
        entryFileNames: 'index.js',
        assetFileNames: 'index.[ext]',
        manualChunks: undefined,
        // Map the externals to the globals WordPress exposes.
        globals: {
          'react':     'React',
          'react-dom': 'ReactDOM',
        },
      },
    },
    manifest: false,
    sourcemap: false,
  },
});
