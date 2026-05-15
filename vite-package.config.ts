//@ts-nocheck
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { resolve } from 'path';

export default defineConfig({
    plugins: [
        tailwindcss(),
        vue(),
    ],
    resolve: {
        alias: {
            '@': resolve(__dirname, 'resources/js'),
        },
    },
    build: {
        outDir: 'dist',
        emptyOutDir: true,
        rollupOptions: {
            input: {
                app: resolve(__dirname, 'resources/js/package-entry.ts'),
            },
            output: {
                entryFileNames: '[name].js',
                chunkFileNames: 'chunks/[name]-[hash].js',
                assetFileNames: (info) => {
                    if (info.name?.endsWith('.css')) return '[name].css';
                    return 'assets/[name].[ext]';
                },
            },
        },
    },
});
