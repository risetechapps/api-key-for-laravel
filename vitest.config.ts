//@ts-nocheck
import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';

// Separado do vite-package.config.ts: aquele gera o dist/ publicado, este só
// roda os testes do painel (sem Tailwind, que não muda comportamento).
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': resolve(__dirname, 'resources/js'),
        },
    },
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.ts'],
        restoreMocks: true,
    },
});
