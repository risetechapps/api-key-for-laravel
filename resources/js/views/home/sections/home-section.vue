<template>
    <section class="relative min-h-screen flex items-center justify-center py-20 px-4 sm:px-6 lg:px-8">
        <!-- Background -->
        <div class="absolute inset-0 overflow-hidden pointer-events-none">
            <div class="absolute top-0 left-1/4 w-96 h-96 bg-indigo-500/20 rounded-full blur-3xl animate-pulse"></div>
            <div class="absolute bottom-0 right-1/4 w-96 h-96 bg-purple-500/20 rounded-full blur-3xl animate-pulse delay-1000"></div>
            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-pink-500/10 rounded-full blur-3xl"></div>
        </div>

        <div class="relative max-w-6xl mx-auto w-full">
            <div class="grid lg:grid-cols-2 gap-16 items-center">

                <!-- Left: Text -->
                <div>
                    <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800/30 mb-8">
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                        </span>
                        <span class="text-sm font-medium text-indigo-700 dark:text-indigo-300">Plataforma de Gerenciamento de API</span>
                    </div>

                    <h1 class="text-4xl sm:text-5xl lg:text-6xl font-bold text-slate-900 dark:text-white mb-6 leading-tight">
                        Gerencie suas
                        <span class="bg-gradient-to-r from-indigo-600 via-purple-600 to-pink-600 bg-clip-text text-transparent">
                            API Keys e Planos
                        </span>
                        com segurança
                    </h1>

                    <p class="text-lg text-slate-600 dark:text-slate-400 mb-10 max-w-xl">
                        Emita chaves de API com hash bcrypt, controle limites de requisições por plano,
                        valide origens e acompanhe o uso em tempo real — tudo em um painel pronto para produção.
                    </p>

                    <div class="flex flex-col sm:flex-row gap-4 mb-14">
                        <router-link
                            to="/register"
                            class="inline-flex items-center justify-center gap-2 px-8 py-4 text-base font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition-all shadow-lg shadow-indigo-500/30 hover:shadow-xl hover:shadow-indigo-500/40 hover:-translate-y-0.5"
                        >
                            Criar conta grátis
                            <PhArrowRight :size="18" weight="bold" />
                        </router-link>
                        <router-link
                            to="/login"
                            class="inline-flex items-center justify-center gap-2 px-8 py-4 text-base font-semibold text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl hover:border-indigo-300 dark:hover:border-indigo-700 transition-all"
                        >
                            <PhSignIn :size="18" />
                            Entrar
                        </router-link>
                    </div>

                    <!-- Stats -->
                    <div class="flex flex-wrap gap-8">
                        <div>
                            <p class="text-2xl font-bold text-slate-900 dark:text-white">Bcrypt</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Hashing de chaves</p>
                        </div>
                        <div class="w-px bg-slate-200 dark:bg-slate-700 hidden sm:block"></div>
                        <div>
                            <p class="text-2xl font-bold text-slate-900 dark:text-white">Grace Period</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Tolerância configurável</p>
                        </div>
                        <div class="w-px bg-slate-200 dark:bg-slate-700 hidden sm:block"></div>
                        <div>
                            <p class="text-2xl font-bold text-slate-900 dark:text-white">Sanctum</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Auth segura</p>
                        </div>
                    </div>
                </div>

                <!-- Right: Code card -->
                <div class="hidden lg:block">
                    <div class="rounded-2xl bg-slate-900 dark:bg-slate-800 border border-slate-700/50 shadow-2xl overflow-hidden">
                        <!-- Window bar -->
                        <div class="flex items-center gap-2 px-4 py-3 bg-slate-800 dark:bg-slate-700/50 border-b border-slate-700/50">
                            <span class="w-3 h-3 rounded-full bg-red-500/80"></span>
                            <span class="w-3 h-3 rounded-full bg-amber-500/80"></span>
                            <span class="w-3 h-3 rounded-full bg-emerald-500/80"></span>
                            <span class="ml-3 text-xs text-slate-400 font-mono">API Request</span>
                        </div>

                        <div class="p-6 font-mono text-sm space-y-4">
                            <!-- Request -->
                            <div>
                                <p class="text-slate-500 text-xs mb-2">// Autenticando com sua chave</p>
                                <p class="text-emerald-400">GET <span class="text-indigo-300">/api/v1/data</span></p>
                                <div class="mt-2 space-y-1">
                                    <p><span class="text-purple-400">X-API-KEY</span><span class="text-slate-400">: </span><span class="text-amber-300">sk_live_a3f8c2...</span></p>
                                    <p><span class="text-purple-400">Origin</span><span class="text-slate-400">: </span><span class="text-slate-300">https://meuapp.com</span></p>
                                </div>
                            </div>

                            <div class="border-t border-slate-700/50 pt-4">
                                <p class="text-slate-500 text-xs mb-2">// Resposta</p>
                                <div class="space-y-1">
                                    <p><span class="text-slate-400">&#123;</span></p>
                                    <p class="pl-4"><span class="text-indigo-300">"success"</span><span class="text-slate-400">: </span><span class="text-emerald-400">true</span><span class="text-slate-400">,</span></p>
                                    <p class="pl-4"><span class="text-indigo-300">"plan"</span><span class="text-slate-400">: </span><span class="text-amber-300">"Premium"</span><span class="text-slate-400">,</span></p>
                                    <p class="pl-4"><span class="text-indigo-300">"requests_used"</span><span class="text-slate-400">: </span><span class="text-purple-400">1.240</span><span class="text-slate-400">,</span></p>
                                    <p class="pl-4"><span class="text-indigo-300">"requests_limit"</span><span class="text-slate-400">: </span><span class="text-purple-400">10.000</span></p>
                                    <p><span class="text-slate-400">&#125;</span></p>
                                </div>
                            </div>

                            <!-- Pill badges -->
                            <div class="flex flex-wrap gap-2 pt-2">
                                <span class="px-2 py-1 rounded-md bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs">200 OK</span>
                                <span class="px-2 py-1 rounded-md bg-indigo-500/10 border border-indigo-500/30 text-indigo-400 text-xs">Plano ativo</span>
                                <span class="px-2 py-1 rounded-md bg-purple-500/10 border border-purple-500/30 text-purple-400 text-xs">Origem validada</span>
                            </div>
                        </div>
                    </div>

                    <!-- Floating badge -->
                    <div class="mt-4 flex justify-end">
                        <div class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 shadow-lg text-sm text-slate-600 dark:text-slate-400">
                            <PhShieldCheck :size="16" class="text-emerald-500" weight="fill" />
                            Chave armazenada com hash bcrypt
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <div class="absolute bottom-8 left-1/2 -translate-x-1/2 animate-bounce">
            <PhCaretDown :size="24" class="text-slate-400" />
        </div>
    </section>
</template>

<script setup lang="ts">
import { PhArrowRight, PhCaretDown, PhSignIn, PhShieldCheck } from '@phosphor-icons/vue';
</script>
