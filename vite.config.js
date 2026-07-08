import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/sass/style.scss',
                'resources/js/app.js',
            ],
            refresh: true,
        }),
    ],
    // base: process.env.APP_URL || '/',
    // server: {
    //     https: true,
    // },
    build: {
        manifest: true,
        outDir: 'public/build',
        emptyOutDir: true,
        rollupOptions: {
            output: {
                assetFileNames: '[name]-[hash][extname]',
            },
        },
    },
});