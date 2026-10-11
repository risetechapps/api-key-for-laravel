<template>
    <div class="relative grid min-h-screen lg:grid-cols-[1.05fr_1fr] bg-white dark:bg-slate-950">
        <!-- Painel da marca -->
        <aside class="relative hidden overflow-hidden lg:flex lg:flex-col lg:justify-between bg-gradient-to-br from-brand-600 via-brand-700 to-brand-900 p-12 text-white">
            <div aria-hidden="true" class="pointer-events-none absolute inset-0">
                <div class="absolute -left-24 -top-24 h-96 w-96 rounded-full bg-white/10 blur-3xl animate-pulse-slow" />
                <div class="absolute -bottom-32 -right-16 h-[28rem] w-[28rem] rounded-full bg-brand-300/20 blur-3xl animate-pulse-slow [animation-delay:2s]" />
                <svg class="absolute inset-0 h-full w-full opacity-[0.07]" xmlns="http://www.w3.org/2000/svg">
                    <defs><pattern id="auth-grid" width="32" height="32" patternUnits="userSpaceOnUse"><path d="M32 0H0V32" fill="none" stroke="white" stroke-width="1" /></pattern></defs>
                    <rect width="100%" height="100%" fill="url(#auth-grid)" />
                </svg>
            </div>

            <router-link to="/" class="relative flex items-center gap-3">
                <img v-if="brand.logo" :src="brand.logo" :alt="brand.name" class="h-10 w-auto max-w-48 object-contain brightness-0 invert" />
                <template v-else>
                    <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-white/15 ring-1 ring-white/25 backdrop-blur"><PhHexagon :size="24" weight="fill" /></span>
                    <span class="text-xl font-bold tracking-tight">{{ brand.name }}</span>
                </template>
            </router-link>

            <div class="relative max-w-md animate-fade-in-up">
                <h2 class="text-4xl font-bold leading-tight tracking-tight">{{ headline }}</h2>
                <ul class="mt-8 space-y-4">
                    <li v-for="(item, i) in bullets" :key="item" class="flex items-start gap-3 text-white/90 animate-fade-in-up" :style="{ animationDelay: `${150 + i * 90}ms` }">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white/15 ring-1 ring-white/20"><PhCheck :size="13" weight="bold" /></span>
                        {{ item }}
                    </li>
                </ul>
            </div>

            <p class="relative text-sm text-white/60">© {{ year }} {{ brand.name }}</p>
        </aside>

        <!-- Formulário -->
        <main class="relative flex flex-col">
            <div class="flex items-center justify-between p-6">
                <router-link to="/" class="flex items-center gap-2.5 lg:invisible">
                    <img v-if="brand.logo" :src="brand.logo" :alt="brand.name" class="h-8 w-auto" />
                    <template v-else>
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-white shadow-lg shadow-brand-600/30"><PhHexagon :size="18" weight="fill" /></span>
                        <span class="font-bold text-slate-900 dark:text-white">{{ brand.name }}</span>
                    </template>
                </router-link>
                <button type="button" class="h-10 w-10 flex items-center justify-center rounded-xl text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" :aria-label="isDark ? 'Tema claro' : 'Tema escuro'" @click="toggleTheme">
                    <PhSun v-if="isDark" :size="19" weight="duotone" class="text-amber-400" />
                    <PhMoon v-else :size="19" weight="duotone" />
                </button>
            </div>

            <div class="flex flex-1 items-center justify-center px-6 pb-12">
                <div class="w-full max-w-sm animate-fade-in-up">
                    <div class="mb-8">
                        <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">{{ title }}</h1>
                        <p v-if="subtitle" class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ subtitle }}</p>
                    </div>
                    <slot />
                    <div v-if="$slots.footer" class="mt-8 text-center text-sm text-slate-500 dark:text-slate-400">
                        <slot name="footer" />
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>

<script setup lang="ts">
/**
 * Moldura das telas de entrada (login, cadastro, senha). O texto do painel
 * da marca vem de `api-key.config.ts` → `auth`, com um padrão neutro.
 */
import { PhHexagon, PhCheck, PhSun, PhMoon } from '@phosphor-icons/vue';
import appConfig from '@/api-key.config';
import { brand } from '@/brand';
import { isDark, toggleTheme } from '@/theme';

defineProps<{ title: string; subtitle?: string }>();

const headline = appConfig.auth?.headline ?? 'Sua API com chave, plano e cobrança sob controle.';
const bullets = appConfig.auth?.bullets ?? [
    'Chaves por ambiente, com permissões e limites próprios',
    'Consumo em tempo real e avisos antes do limite',
    'Assinatura, PIX e cartão com renovação automática',
];
const year = new Date().getFullYear();
</script>
