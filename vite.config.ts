import { defineConfig } from 'vite';

export default defineConfig({
	build: {
		lib: {
			entry: 'src/ts/checkout.ts',
			name: 'CashuCheckout',
			formats: ['iife'],
			fileName: () => 'checkout.js',
		},
		outDir: 'assets/cashu',
	},
});
