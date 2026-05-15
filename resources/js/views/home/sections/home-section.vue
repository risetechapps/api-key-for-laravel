<template>
    <section class="relative min-h-screen flex items-center justify-center py-20 px-4 sm:px-6 lg:px-8">
        <!-- Background Effects -->
        <div class="absolute inset-0 overflow-hidden">
            <div
                class="absolute top-0 left-1/4 w-96 h-96 bg-indigo-500/20 rounded-full blur-3xl animate-pulse"></div>
            <div
                class="absolute bottom-0 right-1/4 w-96 h-96 bg-purple-500/20 rounded-full blur-3xl animate-pulse delay-1000"></div>
            <div
                class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-pink-500/10 rounded-full blur-3xl"></div>
        </div>

        <div class="relative max-w-5xl mx-auto text-center">
            <!-- Badge -->
            <div
                class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800/30 mb-8">
                    <span class="relative flex h-2 w-2">
                        <span
                            class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                    </span>
                <span class="text-sm font-medium text-indigo-700 dark:text-indigo-300">Sistema 100% Online</span>
            </div>

            <!-- Title -->
            <h1 class="text-4xl sm:text-5xl lg:text-7xl font-bold text-slate-900 dark:text-white mb-6 leading-tight">
                Dados Brasileiros
                <br/>
                <span
                    class="bg-gradient-to-r from-indigo-600 via-purple-600 to-pink-600 bg-clip-text text-transparent">
                        em Tempo Real
                    </span>
            </h1>

            <!-- Subtitle -->
            <p class="text-lg sm:text-xl text-slate-600 dark:text-slate-400 max-w-2xl mx-auto mb-10">
                API Gateway com alta disponibilidade para consultas de CNPJ, CPF, CEP,
                clima, feriados e muito mais. Cache inteligente e múltiplos provedores.
            </p>

            <!-- CTA Buttons -->
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4 mb-12">
                <router-link
                    to="/register"
                    class="inline-flex items-center gap-2 px-8 py-4 text-lg font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition-all shadow-lg shadow-indigo-500/30 hover:shadow-xl hover:shadow-indigo-500/40 hover:-translate-y-0.5"
                >
                    Começar Grátis
                    <PhArrowRight :size="20" weight="bold"/>
                </router-link>
            </div>

            <!-- Stats -->
            <div class="flex flex-wrap items-center justify-center gap-8 sm:gap-12">
                <div class="text-center">
                    <p class="text-3xl sm:text-4xl font-bold text-slate-900 dark:text-white">99.9%</p>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Uptime</p>
                </div>
                <div class="w-px h-12 bg-slate-200 dark:bg-slate-700 hidden sm:block"></div>
                <div class="text-center">
                    <p class="text-3xl sm:text-4xl font-bold text-slate-900 dark:text-white"><50ms</p>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Latência</p>
                </div>
                <div class="w-px h-12 bg-slate-200 dark:bg-slate-700 hidden sm:block"></div>
                <div class="text-center">
                    <p class="text-3xl sm:text-4xl font-bold text-slate-900 dark:text-white">11+</p>
                    <p class="text-sm text-slate-500 dark:text-slate-400">APIs</p>
                </div>
            </div>
        </div>

        <!-- Scroll indicator -->
        <div class="absolute bottom-8 left-1/2 -translate-x-1/2 animate-bounce">
            <PhCaretDown :size="24" class="text-slate-400"/>
        </div>
    </section>
</template>

<script setup lang="ts">

import {PhArrowRight, PhCaretDown, PhPlayCircle} from "@phosphor-icons/vue";
</script>

<style scoped>

</style>
