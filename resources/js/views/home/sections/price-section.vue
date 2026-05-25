<template>
    <section id="pricing" class="py-24 px-4 sm:px-6 lg:px-8 bg-white dark:bg-slate-900">
        <div class="max-w-5xl mx-auto">
            <div class="text-center mb-16">
                <h2 class="text-3xl sm:text-4xl font-bold text-slate-900 dark:text-white mb-4">Preços simples</h2>
                <p class="text-lg text-slate-600 dark:text-slate-400">Comece grátis e escale conforme suas
                    necessidades</p>
            </div>

            <div class="grid md:grid-cols-3 gap-8">
                <div
                    v-for="plan in pricingPlans"
                    :key="plan.name"
                    class="relative p-8 rounded-2xl border-2 transition-all"
                    :class="[
                            plan.highlighted
                                ? 'border-indigo-500 bg-indigo-50/50 dark:bg-indigo-900/20 shadow-xl ' +
                                 'shadow-indigo-500/10'
                                : 'border-slate-200 dark:border-slate-700 hover:border-indigo-300 ' +
                                 'dark:hover:border-indigo-700',]">
                    <div v-if="plan.highlighted" class="absolute -top-4 left-1/2 -translate-x-1/2">
                            <span
                                class="bg-indigo-600 text-white text-sm font-semibold px-4 py-1 rounded-full">
                                Popular
                            </span>
                    </div>

                    <h3 class="text-xl font-bold text-slate-900 dark:text-white">{{ plan.name }}</h3>
                    <p class="text-slate-500 dark:text-slate-400 mt-2">{{ plan.description }}</p>

                    <div class="mt-6 mb-8">
                        <span class="text-4xl font-bold text-slate-900 dark:text-white">{{ plan.price }}</span>
                        <span class="text-slate-500 dark:text-slate-400">/{{ billingCycleShort(plan.billing_cycle) }}</span>
                    </div>

                    <ul class="space-y-4 mb-8">
                        <li v-for="feature in planFeatures(plan)" :key="feature" class="flex items-center gap-3">
                            <PhCheckCircle :size="20" weight="fill" class="text-emerald-500"/>
                            <span class="text-slate-700 dark:text-slate-300">{{ feature }}</span>
                        </li>
                    </ul>

                    <router-link
                        :to="'/register'"
                        class="block w-full text-center py-3 rounded-xl font-semibold transition-all"
                        :class="plan.highlighted
                                ? 'bg-indigo-600 hover:bg-indigo-700 text-white'
                                : 'bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 ' +
                                 'text-slate-900 dark:text-white'">
                        Começar
                    </router-link>
                </div>
            </div>
        </div>
    </section>
</template>

<script setup lang="ts">

interface PricingPlan {
    name: string;
    description: string;
    price: string | number;
    billing_cycle: string;
    highlighted: boolean;
    features: string[];
    features_description: string[];
}

import {onMounted, ref} from "vue";
import axios from "axios";
import {PhCheckCircle} from "@phosphor-icons/vue";

const pricingPlans = ref<PricingPlan[]>([]);

function billingCycleShort(cycle: string): string {
    if (cycle === 'weekly') return 'semana';
    if (cycle === 'monthly') return 'mês';
    if (cycle === 'yearly' || cycle === 'annually') return 'ano';
    return cycle;
}

function planFeatures(plan: PricingPlan & { features?: Array<string | { key: string; name: string }> }): string[] {
    if (plan.features?.length) return plan.features.map(f => typeof f === 'string' ? f : (f.name ?? f.key));
    if (plan.features_description?.length) return plan.features_description;
    return [];
}

const loadPlans = async () => {
    try {
        const response = await axios.get('/dashboard/plans');

        if (response.data.success === true) {
            return response.data.data;
        }

    } catch (e) {
        return [];
    }
}

onMounted(async () => {
    pricingPlans.value =  await loadPlans();
});
</script>


<style scoped>

</style>
