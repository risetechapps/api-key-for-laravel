<template>
    <div class="overflow-hidden">
        <!-- Hero Section -->

        <home-section/>

        <features-section/>

        <price-section/>

        <docs-section/>

        <footer class="py-12 px-4 sm:px-6 lg:px-8 border-t border-slate-200 dark:border-slate-800">
            <div class="max-w-7xl mx-auto">
                <div class="flex flex-col md:flex-row items-center justify-between gap-6">
                    <div class="flex items-center gap-3">
                        <div
                            class="w-10 h-10 rounded-xl bg-gradient-to-br from-brand-500 to-brand-600
                                flex items-center justify-center shadow-lg shadow-brand-500/30">
                            <PhHexagon :size="24" weight="fill" class="text-white"/>
                        </div>
                        <span class="text-lg font-bold tracking-tight text-slate-900 dark:text-white">{{ brand.name }}</span>
                    </div>

                    <div class="flex items-center gap-8">
                        <a href="#docs"
                           class="text-sm text-slate-600 dark:text-slate-400 hover:text-brand-600
                            dark:hover:text-brand-400 transition-colors">Documentação</a>
                        <router-link to="/support"
                           class="text-sm text-slate-600 dark:text-slate-400 hover:text-brand-600
                           dark:hover:text-brand-400 transition-colors">Suporte</router-link>
                        <router-link to="/privacy"
                           class="text-sm text-slate-600 dark:text-slate-400 hover:text-brand-600
                           dark:hover:text-brand-400 transition-colors">Privacidade</router-link>
                        <router-link to="/terms"
                           class="text-sm text-slate-600 dark:text-slate-400 hover:text-brand-600
                           dark:hover:text-brand-400 transition-colors">Termos</router-link>
                    </div>

                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        © {{ currentYear }} {{ brand.name }}. Todos os direitos reservados.
                    </p>
                </div>
            </div>
        </footer>
    </div>
</template>

<script setup>
import {PhHexagon,} from '@phosphor-icons/vue';
import { brand } from '@/brand';
import HomeSection from "@/views/home/sections/home-section.vue";
import FeaturesSection from "@/views/home/sections/features-section.vue";
import PriceSection from "@/views/home/sections/price-section.vue";
import DocsSection from "@/views/home/sections/docs-section.vue";

const currentYear = new Date().getFullYear();

</script>
