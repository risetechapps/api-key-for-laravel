<template>
    <section id="features" class="py-24 px-4 sm:px-6 lg:px-8 bg-white dark:bg-slate-900">
        <div class="max-w-7xl mx-auto">

            <div class="text-center mb-16">
                <h2 class="text-3xl sm:text-4xl font-bold text-slate-900 dark:text-white mb-4">
                    Tudo que você precisa para gerenciar APIs
                </h2>
                <p class="text-lg text-slate-600 dark:text-slate-400 max-w-2xl mx-auto">
                    Do controle de acesso à cobrança recorrente — um pacote Laravel pronto para produção.
                </p>
            </div>

            <!-- Feature grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 mb-20">
                <div
                    v-for="feature in features"
                    :key="feature.name"
                    class="group p-6 rounded-2xl bg-slate-50 dark:bg-slate-800 border border-slate-200
                           dark:border-slate-700 hover:border-indigo-300 dark:hover:border-indigo-700
                           transition-all hover:shadow-xl hover:shadow-indigo-500/10 hover:-translate-y-1"
                >
                    <div
                        class="w-12 h-12 rounded-xl flex items-center justify-center mb-4"
                        :class="feature.bgClass"
                    >
                        <component :is="feature.icon" :size="24" :class="feature.iconClass" />
                    </div>
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-2">{{ feature.name }}</h3>
                    <p class="text-slate-600 dark:text-slate-400 text-sm leading-relaxed">{{ feature.description }}</p>
                </div>
            </div>

            <!-- How it works -->
            <div class="rounded-3xl bg-gradient-to-br from-indigo-50 to-purple-50 dark:from-indigo-900/20 dark:to-purple-900/20 border border-indigo-100 dark:border-indigo-800/30 p-8 sm:p-12">
                <div class="text-center mb-12">
                    <h3 class="text-2xl sm:text-3xl font-bold text-slate-900 dark:text-white mb-3">
                        Como funciona
                    </h3>
                    <p class="text-slate-600 dark:text-slate-400">Em 3 passos o seu cliente já está operando</p>
                </div>

                <div class="grid sm:grid-cols-3 gap-8 relative">
                    <!-- Connector line (desktop) -->
                    <div class="hidden sm:block absolute top-6 left-1/3 right-1/3 h-px bg-gradient-to-r from-indigo-300 to-purple-300 dark:from-indigo-700 dark:to-purple-700"></div>

                    <div v-for="step in steps" :key="step.number" class="text-center relative">
                        <div class="w-12 h-12 rounded-full bg-white dark:bg-slate-800 border-2 border-indigo-300 dark:border-indigo-700 flex items-center justify-center mx-auto mb-4 shadow-md relative z-10">
                            <span class="text-sm font-bold text-indigo-600 dark:text-indigo-400">{{ step.number }}</span>
                        </div>
                        <h4 class="font-semibold text-slate-900 dark:text-white mb-2">{{ step.title }}</h4>
                        <p class="text-sm text-slate-600 dark:text-slate-400">{{ step.description }}</p>
                    </div>
                </div>
            </div>

        </div>
    </section>
</template>

<script setup lang="ts">
import {
    PhKey,
    PhCreditCard,
    PhShieldCheck,
    PhChartLine,
    PhTimer,
    PhGlobe,
    PhBell,
    PhTag,
    PhLock,
} from '@phosphor-icons/vue';

const features = [
    {
        name: 'API Keys com Hashing',
        description: 'Chaves geradas com 128 caracteres aleatórios e armazenadas com bcrypt. Nunca expostas em texto plano no banco.',
        icon: PhKey,
        bgClass: 'bg-indigo-100 dark:bg-indigo-900/30',
        iconClass: 'text-indigo-600 dark:text-indigo-400',
    },
    {
        name: 'Planos de Assinatura',
        description: 'Crie planos com ciclos mensais, anuais ou customizados. Cada plano define limites de requisições e features habilitadas.',
        icon: PhCreditCard,
        bgClass: 'bg-purple-100 dark:bg-purple-900/30',
        iconClass: 'text-purple-600 dark:text-purple-400',
    },
    {
        name: 'Validação de Origem',
        description: 'Restrinja o uso de cada chave a domínios específicos. Proteção similar a CORS no nível da autenticação.',
        icon: PhGlobe,
        bgClass: 'bg-pink-100 dark:bg-pink-900/30',
        iconClass: 'text-pink-600 dark:text-pink-400',
    },
    {
        name: 'Período de Tolerância',
        description: 'Planos expirados entram em grace period configurável. O cliente mantém acesso enquanto renova sem interrupção.',
        icon: PhTimer,
        bgClass: 'bg-amber-100 dark:bg-amber-900/30',
        iconClass: 'text-amber-600 dark:text-amber-400',
    },
    {
        name: 'Log de Requisições',
        description: 'Cada chamada é registrada com endpoint, método, status e timestamp. Visualize o histórico completo no dashboard.',
        icon: PhChartLine,
        bgClass: 'bg-emerald-100 dark:bg-emerald-900/30',
        iconClass: 'text-emerald-600 dark:text-emerald-400',
    },
    {
        name: 'Cupons de Desconto',
        description: 'Crie cupons com percentual de desconto, limite de usos e data de expiração. Aplicados automaticamente no checkout.',
        icon: PhTag,
        bgClass: 'bg-blue-100 dark:bg-blue-900/30',
        iconClass: 'text-blue-600 dark:text-blue-400',
    },
    {
        name: 'Notificações Automáticas',
        description: 'E-mails em português para expiração de plano, início do grace period e redefinição de senha — prontos e configuráveis.',
        icon: PhBell,
        bgClass: 'bg-rose-100 dark:bg-rose-900/30',
        iconClass: 'text-rose-600 dark:text-rose-400',
    },
    {
        name: 'Rate Limiting',
        description: 'Throttle configurável nos endpoints de login e registro. Contadores atômicos para evitar race conditions em alta carga.',
        icon: PhShieldCheck,
        bgClass: 'bg-teal-100 dark:bg-teal-900/30',
        iconClass: 'text-teal-600 dark:text-teal-400',
    },
    {
        name: 'Autenticação Sanctum',
        description: 'Tokens pessoais via Laravel Sanctum. Logout revoga todos os tokens ativos. Verificação de e-mail integrada.',
        icon: PhLock,
        bgClass: 'bg-violet-100 dark:bg-violet-900/30',
        iconClass: 'text-violet-600 dark:text-violet-400',
    },
];

const steps = [
    {
        number: '1',
        title: 'Crie uma conta',
        description: 'Cadastre-se, confirme o e-mail e acesse o painel.',
    },
    {
        number: '2',
        title: 'Escolha um plano',
        description: 'Selecione o plano ideal e conclua o pagamento via MercadoPago.',
    },
    {
        number: '3',
        title: 'Use sua API Key',
        description: 'Copie sua chave, adicione no header X-API-KEY e comece a consumir.',
    },
];
</script>
