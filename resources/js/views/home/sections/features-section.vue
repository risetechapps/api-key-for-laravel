<template>
    <section id="features" class="py-24 px-4 sm:px-6 lg:px-8 bg-white dark:bg-slate-900">
        <div class="max-w-7xl mx-auto">
            <div class="text-center mb-16">
                <h2 class="text-3xl sm:text-4xl font-bold text-slate-900 dark:text-white mb-4">
                    Tudo que você precisa
                </h2>
                <p class="text-lg text-slate-600 dark:text-slate-400 max-w-2xl mx-auto">
                    Uma API completa para consulta de dados brasileiros com alta disponibilidade e cache
                    inteligente.
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <div
                    v-for="feature in features"
                    :key="feature.name"
                    class="group p-6 rounded-2xl bg-slate-50 dark:bg-slate-800 border border-slate-200
                    dark:border-slate-700 hover:border-indigo-300 dark:hover:border-indigo-700 transition-all
                     hover:shadow-xl hover:shadow-indigo-500/10 hover:-translate-y-1">

                    <div
                        class="w-12 h-12 rounded-xl flex items-center justify-center mb-4 transition-colors"
                        :class="feature.bgClass">
                        <component
                            :is="feature.icon"
                            :size="24"
                            :class="feature.iconClass"
                        />
                    </div>

                    <h3 class="text-xl font-semibold text-slate-900 dark:text-white mb-2">{{ feature.name }}</h3>
                    <p class="text-slate-600 dark:text-slate-400">{{ feature.description }}</p>
                </div>
            </div>
        </div>
    </section>
</template>

<script setup lang="ts">

import {PhBuildings, PhCalendar, PhCloudSun, PhGlobe, PhIdentificationCard, PhMapPin,} from '@phosphor-icons/vue';

const features = [
    {
        name: 'Consulta CNPJ',
        description: 'Dados completos de empresas: razão social, sócios, endereço, atividades e mais.',
        icon: PhBuildings,
        bgClass: 'bg-indigo-100 dark:bg-indigo-900/30',
        iconClass: 'text-indigo-600 dark:text-indigo-400',
    },
    {
        name: 'Consulta CPF',
        description: 'Validação e informações de CPF com múltiplos provedores de fallback.',
        icon: PhIdentificationCard,
        bgClass: 'bg-purple-100 dark:bg-purple-900/30',
        iconClass: 'text-purple-600 dark:text-purple-400',
    },
    {
        name: 'Consulta CEP',
        description: 'Endereços completos com ViaCEP, OpenCEP e outros provedores.',
        icon: PhMapPin,
        bgClass: 'bg-pink-100 dark:bg-pink-900/30',
        iconClass: 'text-pink-600 dark:text-pink-400',
    },
    {
        name: 'Previsão do Tempo',
        description: 'Dados meteorológicos atuais e previsões para qualquer cidade.',
        icon: PhCloudSun,
        bgClass: 'bg-amber-100 dark:bg-amber-900/30',
        iconClass: 'text-amber-600 dark:text-amber-400',
    },
    {
        name: 'Feriados',
        description: 'Calendário de feriados nacionais e estaduais atualizado anualmente.',
        icon: PhCalendar,
        bgClass: 'bg-emerald-100 dark:bg-emerald-900/30',
        iconClass: 'text-emerald-600 dark:text-emerald-400',
    },
    {
        name: 'WHOIS',
        description: 'Informações de registro de domínios nacionais e internacionais.',
        icon: PhGlobe,
        bgClass: 'bg-blue-100 dark:bg-blue-900/30',
        iconClass: 'text-blue-600 dark:text-blue-400',
    },
];
</script>

<style scoped>

</style>
