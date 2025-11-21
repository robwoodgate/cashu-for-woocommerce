import { defineConfig } from 'vite';

export default defineConfig({
  build: {
    lib: {
      entry: 'src/checkout.ts',
      name: 'CashuCheckout',
      formats: ['iife'],
      fileName: () => 'cashu-checkout.js',
    },
    outDir: 'assets/dist',
  },
});
