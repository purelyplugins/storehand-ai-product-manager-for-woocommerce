import type { Config } from 'tailwindcss';

export default {
  content: [ './src/**/*.{ts,tsx}' ],
  // Prefix all utilities with wp- to avoid conflicts with WP Admin CSS.
  prefix: 'wp-',
  corePlugins: {
    preflight: false, // Don't reset WP admin base styles.
  },
} satisfies Config;
