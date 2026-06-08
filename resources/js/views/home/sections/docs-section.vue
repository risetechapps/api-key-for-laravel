<template>
    <section id="docs" class="py-24 px-4 sm:px-6 lg:px-8 bg-slate-50 dark:bg-slate-950">
        <div class="max-w-7xl mx-auto">

            <div class="text-center mb-16">
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 text-xs font-semibold mb-4">
                    <PhBookOpen :size="16" weight="fill" />
                    Documentação
                </div>
                <h2 class="text-3xl sm:text-4xl font-bold text-slate-900 dark:text-white mb-4">
                    Comece em minutos
                </h2>
                <p class="text-lg text-slate-600 dark:text-slate-400 max-w-2xl mx-auto">
                    Autentique com o header <code class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-indigo-600 dark:text-indigo-400 text-sm">X-API-KEY</code> e consuma a API. Todas as rotas usam o prefixo <code class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-indigo-600 dark:text-indigo-400 text-sm">/api/v1</code>.
                </p>
            </div>

            <div class="grid lg:grid-cols-2 gap-8 items-start">
                <!-- Quick start: exemplo de requisição -->
                <div class="rounded-2xl overflow-hidden border border-slate-200 dark:border-slate-700 shadow-xl shadow-slate-200/50 dark:shadow-none">
                    <div class="flex items-center justify-between px-4 py-3 bg-slate-100 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700">
                        <div class="flex items-center gap-2 text-slate-500 dark:text-slate-400">
                            <PhTerminalWindow :size="18" />
                            <span class="text-xs font-semibold uppercase tracking-wider">Exemplo de requisição</span>
                        </div>
                        <button
                            @click="copyExample"
                            class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-500 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">
                            <component :is="copied ? PhCheck : PhCopy" :size="16" />
                            {{ copied ? 'Copiado' : 'Copiar' }}
                        </button>
                    </div>
                    <pre class="p-5 text-sm leading-relaxed overflow-x-auto bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-200"><code>{{ curlExample }}</code></pre>
                </div>

                <!-- Endpoints principais -->
                <div class="space-y-3">
                    <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-2">
                        Endpoints principais
                    </h3>
                    <div
                        v-for="endpoint in endpoints"
                        :key="endpoint.method + endpoint.path"
                        class="flex items-center gap-3 p-3 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                        <span
                            class="shrink-0 w-16 text-center text-xs font-bold px-2 py-1 rounded-md"
                            :class="methodClass(endpoint.method)">
                            {{ endpoint.method }}
                        </span>
                        <code class="text-sm text-slate-800 dark:text-slate-200 font-mono">{{ endpoint.path }}</code>
                        <span class="ml-auto text-xs text-slate-500 dark:text-slate-400 hidden sm:block">{{ endpoint.label }}</span>
                    </div>
                </div>
            </div>

            <!-- Notas de comportamento -->
            <div class="grid sm:grid-cols-3 gap-6 mt-12">
                <div
                    v-for="note in notes"
                    :key="note.title"
                    class="p-6 rounded-2xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center mb-3" :class="note.bgClass">
                        <component :is="note.icon" :size="20" :class="note.iconClass" />
                    </div>
                    <h4 class="font-semibold text-slate-900 dark:text-white mb-1">{{ note.title }}</h4>
                    <p class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed">{{ note.description }}</p>
                </div>
            </div>

        </div>
    </section>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue';
import {
    PhBookOpen,
    PhTerminalWindow,
    PhCopy,
    PhCheck,
    PhKey,
    PhGauge,
    PhGlobe,
} from '@phosphor-icons/vue';

const baseUrl = 'https://***';

const curlExample = computed(() =>
`curl --location '${baseUrl}/api/v1/ttt' \\
  --header 'X-API-KEY: sua_chave_aqui' \\
  --header 'Accept: application/json'`
);

const copied = ref(false);

async function copyExample() {
    try {
        await navigator.clipboard.writeText(curlExample.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch (_) {
        // clipboard indisponível (ex.: contexto não-seguro) — ignora silenciosamente
    }
}

const endpoints = [
    { method: 'POST', path: '/api/v1/register', label: 'Cria conta + API key' },
    { method: 'POST', path: '/api/v1/login', label: 'Retorna token Sanctum' },
    { method: 'GET', path: '/api/v1/auth/me', label: 'Dados do usuário' },
    { method: 'GET', path: '/api/v1/dashboard/plans', label: 'Lista de planos' },
    { method: 'POST', path: '/api/v1/dashboard/checkout/process', label: 'Assina um plano' },
];

function methodClass(method: string): string {
    switch (method) {
        case 'GET':
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400';
        case 'POST':
            return 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400';
        case 'PUT':
            return 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400';
        case 'DELETE':
            return 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-400';
        default:
            return 'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-300';
    }
}

const notes = [
    {
        title: 'Autenticação',
        description: 'Envie sua chave no header X-API-KEY. Chaves inválidas ou inativas retornam 401.',
        icon: PhKey,
        bgClass: 'bg-indigo-100 dark:bg-indigo-900/30',
        iconClass: 'text-indigo-600 dark:text-indigo-400',
    },
    {
        title: 'Limite de requisições',
        description: 'Cada plano define um limite mensal. Ao estourar, a API responde 402/429 até a renovação.',
        icon: PhGauge,
        bgClass: 'bg-amber-100 dark:bg-amber-900/30',
        iconClass: 'text-amber-600 dark:text-amber-400',
    },
    {
        title: 'Validação de origem',
        description: 'Se a chave tiver origens permitidas, chamadas de domínios não listados recebem 403.',
        icon: PhGlobe,
        bgClass: 'bg-pink-100 dark:bg-pink-900/30',
        iconClass: 'text-pink-600 dark:text-pink-400',
    },
];
</script>
