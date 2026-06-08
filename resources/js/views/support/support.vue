<template>
    <section class="py-20 px-4 sm:px-6 lg:px-8 bg-slate-50 dark:bg-slate-900 min-h-screen">
        <div class="max-w-3xl mx-auto">
            <!-- Header -->
            <div class="mb-12">
                <router-link
                    to="/"
                    class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors mb-6">
                    <PhArrowLeft :size="16" />
                    Voltar para o início
                </router-link>
                <h1 class="text-3xl sm:text-4xl font-bold text-slate-900 dark:text-white">Suporte</h1>
                <p class="mt-3 text-slate-600 dark:text-slate-400">
                    Precisa de ajuda? Estamos por aqui para resolver o que for preciso.
                </p>
            </div>

            <!-- Contact cards -->
            <div class="grid sm:grid-cols-2 gap-4 mb-10">
                <div class="p-6 rounded-2xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                    <div class="w-11 h-11 rounded-xl bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center mb-3">
                        <PhEnvelopeSimple :size="22" class="text-indigo-600 dark:text-indigo-400" />
                    </div>
                    <h3 class="font-semibold text-slate-900 dark:text-white mb-1">E-mail</h3>
                    <a :href="`mailto:${supportEmail}`" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline break-all">
                        {{ supportEmail }}
                    </a>
                </div>

                <div class="p-6 rounded-2xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                    <div class="w-11 h-11 rounded-xl bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center mb-3">
                        <PhClock :size="22" class="text-emerald-600 dark:text-emerald-400" />
                    </div>
                    <h3 class="font-semibold text-slate-900 dark:text-white mb-1">Horário de atendimento</h3>
                    <p class="text-sm text-slate-600 dark:text-slate-400">Segunda a sexta, das 9h às 18h (BRT)</p>
                </div>
            </div>

            <!-- CTA -->
            <div class="p-6 rounded-2xl bg-gradient-to-br from-indigo-50 to-purple-50 dark:from-indigo-900/20 dark:to-purple-900/20 border border-indigo-100 dark:border-indigo-800/30 mb-12 flex flex-col sm:flex-row items-center justify-between gap-4">
                <div>
                    <h3 class="font-semibold text-slate-900 dark:text-white">Não encontrou o que precisava?</h3>
                    <p class="text-sm text-slate-600 dark:text-slate-400">Mande sua dúvida que respondemos o quanto antes.</p>
                </div>
                <a
                    :href="`mailto:${supportEmail}`"
                    class="shrink-0 inline-flex items-center gap-2 px-5 py-2.5 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition-colors shadow-lg shadow-indigo-500/30">
                    <PhPaperPlaneTilt :size="18" />
                    Falar com o suporte
                </a>
            </div>

            <!-- FAQ -->
            <div>
                <h2 class="text-xl font-bold text-slate-900 dark:text-white mb-4">Perguntas frequentes</h2>
                <div class="space-y-3">
                    <details
                        v-for="(item, i) in faq"
                        :key="i"
                        class="group rounded-2xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 overflow-hidden">
                        <summary class="flex items-center justify-between gap-3 p-4 cursor-pointer list-none">
                            <span class="text-sm font-medium text-slate-900 dark:text-white">{{ item.q }}</span>
                            <PhCaretDown :size="18" class="text-slate-400 transition-transform group-open:rotate-180 shrink-0" />
                        </summary>
                        <div class="px-4 pb-4 -mt-1">
                            <p class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed">{{ item.a }}</p>
                        </div>
                    </details>
                </div>
            </div>
        </div>
    </section>
</template>

<script setup lang="ts">
import {
    PhArrowLeft,
    PhEnvelopeSimple,
    PhClock,
    PhPaperPlaneTilt,
    PhCaretDown,
} from '@phosphor-icons/vue';

// TODO: ajuste para o e-mail real de suporte.
const supportEmail = 'suporte@seudominio.com.br';

const faq = [
    {
        q: 'Perdi minha chave de API. Como recupero?',
        a: 'A chave é exibida apenas uma vez na geração. Se perdeu, gere uma nova em Painel → Perfil → Regenerar chave. A chave anterior deixa de funcionar imediatamente.',
    },
    {
        q: 'Recebi erro 401 nas requisições.',
        a: 'Verifique se está enviando o header X-API-KEY com a chave correta e se a chave está ativa. Chaves de planos expirados (após o período de tolerância) são desativadas.',
    },
    {
        q: 'Recebi erro 402 ou 429.',
        a: 'Significa que o limite de requisições do seu plano foi atingido ou que a feature exige um plano superior. Faça upgrade no Painel → Planos para liberar mais capacidade.',
    },
    {
        q: 'Como cancelo minha assinatura?',
        a: 'Você pode cancelar a qualquer momento pelo painel. O acesso permanece ativo até o fim do período já pago.',
    },
];
</script>
