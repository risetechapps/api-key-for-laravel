<template>
    <div class="overflow-hidden">
        <!-- Hero Section -->

        <home-section/>

        <features-section/>

        <price-section/>

        <footer class="py-12 px-4 sm:px-6 lg:px-8 border-t border-slate-200 dark:border-slate-800">
            <div class="max-w-7xl mx-auto">
                <div class="flex flex-col md:flex-row items-center justify-between gap-6">
                    <div class="flex items-center gap-3">
                        <div
                            class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600
                                flex items-center justify-center shadow-lg shadow-indigo-500/30">
                            <PhHexagon :size="24" weight="fill" class="text-white"/>
                        </div>
                        <span
                            class="text-lg font-bold bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text
                                text-transparent">
                            Api Key
                        </span>
                    </div>

                    <div class="flex items-center gap-8">
                        <a href="#"
                           class="text-sm text-slate-600 dark:text-slate-400 hover:text-indigo-600
                            dark:hover:text-indigo-400 transition-colors">Documentação</a>
                        <a href="#"
                           class="text-sm text-slate-600 dark:text-slate-400 hover:text-indigo-600
                           dark:hover:text-indigo-400 transition-colors">Status</a>
                        <a href="#"
                           class="text-sm text-slate-600 dark:text-slate-400 hover:text-indigo-600
                           dark:hover:text-indigo-400 transition-colors">Suporte</a>
                        <a href="#"
                           class="text-sm text-slate-600 dark:text-slate-400 hover:text-indigo-600
                           dark:hover:text-indigo-400 transition-colors">Termos</a>
                    </div>

                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        © {{ currentYear }} Api Key. Todos os direitos reservados.
                    </p>
                </div>
            </div>
        </footer>
    </div>
</template>

<script setup>
import {PhHexagon,} from '@phosphor-icons/vue';
import HomeSection from "@/views/home/sections/home-section.vue";
import FeaturesSection from "@/views/home/sections/features-section.vue";
import PriceSection from "@/views/home/sections/price-section.vue";

const currentYear = new Date().getFullYear();

</script>
