//@ts-nocheck
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        tailwindcss(),
        vue(),
    ],
    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
    // Adicione este bloco abaixo:
    server: {
        watch: {
            usePolling: true,
        },
    },
    build: {
        watch: {
            // Garante que o build monitore as pastas de recursos
            include: 'resources/**',
        },
        // Opcional: limpa a pasta build antes de cada nova compilação no watch
        emptyOutDir: true,
    },
});
