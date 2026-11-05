/**
 * Builds the settings panel into assets/admin/app.js + app.css (one IIFE bundle, no module loader
 * needed in wp-admin).
 */
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath( new URL( '.', import.meta.url ) );

export default defineConfig( {
	root,
	plugins: [ react(), tailwindcss() ],
	define: { 'process.env.NODE_ENV': JSON.stringify( 'production' ) },
	build: {
		outDir: fileURLToPath( new URL( '../assets/admin', import.meta.url ) ),
		emptyOutDir: true,
		cssCodeSplit: false,
		lib: {
			entry: fileURLToPath( new URL( './main.tsx', import.meta.url ) ),
			name: 'ctcAdmin',
			formats: [ 'iife' ],
			fileName: () => 'app.js',
			cssFileName: 'app',
		},
		rollupOptions: { output: { assetFileNames: 'app.[ext]' } },
	},
} );
