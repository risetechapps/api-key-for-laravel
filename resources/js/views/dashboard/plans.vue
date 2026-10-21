<template>
    <div>
        <PageHeader title="Planos" subtitle="Escolha o plano certo para o volume da sua aplicação. Troque quando quiser." :icon="PhCrown" />

        <!-- Plano atual -->
        <Card v-if="currentPlan" :padding="false" class="mb-10 overflow-hidden">
            <div class="flex flex-col gap-6 p-6 md:flex-row md:items-center md:justify-between">
                <div class="flex items-center gap-4">
                    <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-500 to-brand-700 text-white shadow-lg shadow-brand-600/30">
                        <PhCrown :size="26" weight="fill" />
                    </span>
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Seu plano</p>
                        <h2 class="text-xl font-bold text-slate-900 dark:text-white">{{ currentPlan.name }}</h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ currentPlan.description }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-8">
                    <div>
                        <p class="text-2xl font-bold tabular-nums text-slate-900 dark:text-white">{{ Number(currentPlan.request_limit).toLocaleString('pt-BR') }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">requisições por período</p>
                    </div>
                    <div class="h-10 w-px bg-slate-200 dark:bg-slate-800" />
                    <div>
                        <p class="text-2xl font-bold text-slate-900 dark:text-white">{{ currentPlan.formatted_price || format.money(currentPlan.raw_price ?? currentPlan.price) }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">por {{ cycle(currentPlan.billing_cycle) }}</p>
                    </div>
                </div>
            </div>
        </Card>

        <!-- Planos disponíveis -->
        <div v-if="loading" class="grid gap-6 md:grid-cols-3">
            <Card v-for="i in 3" :key="i"><Skeleton :lines="8" /></Card>
        </div>

        <div v-else class="ui-stagger grid items-stretch justify-center gap-6 md:grid-cols-[repeat(auto-fit,minmax(18rem,22rem))]">
            <div
                v-for="(plan, i) in availablePlans"
                :key="plan.id"
                :style="{ '--i': i }"
                :class="[
                    'relative flex flex-col rounded-3xl border p-7 transition-[transform,box-shadow] duration-300 hover:-translate-y-1',
                    plan.is_recommended
                        ? 'border-transparent bg-gradient-to-b from-brand-600 to-brand-800 text-white shadow-2xl shadow-brand-600/30'
                        : 'border-slate-200/80 bg-white shadow-sm hover:shadow-xl hover:shadow-slate-200/60 dark:border-slate-800 dark:bg-slate-900 dark:hover:shadow-black/30',
                    plan.is_current && !plan.is_recommended ? 'ring-2 ring-brand-500' : '',
                ]"
            >
                <span v-if="plan.is_recommended" class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-amber-400 px-3 py-1 text-[11px] font-bold uppercase tracking-wider text-amber-950 shadow-lg">Mais escolhido</span>

                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 :class="['text-lg font-bold', plan.is_recommended ? 'text-white' : 'text-slate-900 dark:text-white']">{{ plan.name }}</h3>
                        <p :class="['mt-1 text-sm', plan.is_recommended ? 'text-white/75' : 'text-slate-500 dark:text-slate-400']">{{ plan.description }}</p>
                    </div>
                    <Badge v-if="plan.is_current" :tone="plan.is_recommended ? 'neutral' : 'brand'" dot>Atual</Badge>
                </div>

                <div class="mt-6 flex items-baseline gap-1">
                    <span :class="['text-4xl font-extrabold tracking-tight', plan.is_recommended ? 'text-white' : 'text-slate-900 dark:text-white']">{{ plan.price }}</span>
                    <span :class="plan.is_recommended ? 'text-white/70' : 'text-slate-500 dark:text-slate-400'">/{{ cycle(plan.billing_cycle) }}</span>
                </div>

                <p v-if="plan.trial_days > 0 && authStore.user?.trial_available" :class="['mt-2 inline-flex items-center gap-1.5 text-sm font-semibold', plan.is_recommended ? 'text-amber-300' : 'text-brand-600 dark:text-brand-400']">
                    <PhGift :size="16" weight="fill" /> {{ plan.trial_days }} dias grátis para testar
                </p>

                <ul class="mt-6 flex-1 space-y-3">
                    <li v-for="item in bullets(plan)" :key="item" class="flex items-start gap-3 text-sm">
                        <span :class="['mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full', plan.is_recommended ? 'bg-white/20' : 'bg-emerald-100 dark:bg-emerald-500/15']">
                            <PhCheck :size="11" weight="bold" :class="plan.is_recommended ? 'text-white' : 'text-emerald-600 dark:text-emerald-400'" />
                        </span>
                        <span :class="plan.is_recommended ? 'text-white/90' : 'text-slate-700 dark:text-slate-300'" v-html="item" />
                    </li>
                </ul>

                <Button
                    class="mt-8"
                    block
                    size="lg"
                    :variant="plan.is_recommended ? 'secondary' : plan.is_current ? 'outline' : 'primary'"
                    :class="plan.is_recommended ? '!bg-white !text-brand-700 hover:!bg-brand-50' : ''"
                    :disabled="plan.is_current"
                    :icon-right="plan.is_current ? null : PhArrowRight"
                    @click="openCheckout(plan)"
                >
                    {{ plan.is_current ? 'Seu plano atual' : plan.trial_days > 0 && authStore.user?.trial_available ? 'Começar teste grátis' : 'Assinar agora' }}
                </Button>
            </div>
        </div>

        <CheckoutModal v-model="showCheckout" :plan="checkoutPlan" @success="onCheckoutSuccess" />

        <!-- Perguntas frequentes -->
        <div class="mx-auto mt-16 max-w-3xl">
            <h2 class="text-center text-xl font-bold text-slate-900 dark:text-white">Perguntas frequentes</h2>
            <div class="mt-6 divide-y divide-slate-200 rounded-2xl border border-slate-200/80 bg-white dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-900">
                <div v-for="(faq, i) in faqs" :key="faq.question">
                    <button type="button" class="flex w-full items-center justify-between gap-4 px-6 py-4 text-left" :aria-expanded="open === i" @click="open = open === i ? null : i">
                        <span class="font-medium text-slate-900 dark:text-white">{{ faq.question }}</span>
                        <span :class="['flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-100 transition-transform duration-300 dark:bg-slate-800', open === i ? 'rotate-45' : '']">
                            <PhPlus :size="14" weight="bold" class="text-slate-500" />
                        </span>
                    </button>
                    <div class="grid transition-[grid-template-rows] duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]" :class="open === i ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'">
                        <p class="overflow-hidden px-6 text-sm leading-relaxed text-slate-600 dark:text-slate-400" :class="open === i ? 'pb-5' : ''">{{ faq.answer }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { PhCrown, PhCheck, PhPlus, PhArrowRight, PhGift } from '@phosphor-icons/vue';
import { useAuthStore } from '@/stores/auth';
import { useDashboardStore } from '@/stores/dashboard';
import { escapeHtml } from '@/utils/escapeHtml';
import { Badge, Button, Card, PageHeader, Skeleton, format } from '@/ui';
import CheckoutModal from '@/views/dashboard/CheckoutModal.vue';

const authStore = useAuthStore();
const dashboardStore = useDashboardStore();

const loading = ref(true);
const showCheckout = ref(false);
const checkoutPlan = ref<any>(null);
const open = ref<number | null>(0);

const currentPlan = computed<any>(() => authStore.user?.active_plan?.plan);

const availablePlans = computed<any[]>(() => [...dashboardStore.plans]
    .map((plan: any) => ({ ...plan, is_current: plan.id === currentPlan.value?.id, is_recommended: plan.code === 'pro' }))
    .sort((a, b) => a.raw_price - b.raw_price));

function cycle(c: string): string {
    return ({ weekly: 'semana', monthly: 'mês', yearly: 'ano', annually: 'ano' } as Record<string, string>)[c] ?? c;
}

// Texto do plano vem do banco: é escapado antes de ir para o v-html, que só
// existe para o <strong> do número.
function bullets(plan: any): string[] {
    const out = [`<strong>${Number(plan.request_limit).toLocaleString('pt-BR')}</strong> requisições por ${cycle(plan.billing_cycle)}`];
    if (plan.overage_price) {
        out.push(`Sem bloqueio no limite: excedente a ${Number(plan.overage_price).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 4 })} por requisição`);
    }
    for (const f of plan.features ?? []) {
        const name = typeof f === 'string' ? f : f?.name;
        if (name) out.push(escapeHtml(name));
    }
    return out;
}

const faqs = [
    { question: 'Posso mudar de plano a qualquer momento?', answer: 'Sim. A troca vale na hora, e o que sobrou do período atual vira crédito na compra do novo plano.' },
    { question: 'O que acontece se eu passar do limite de requisições?', answer: 'Avisamos por e-mail quando o consumo chega perto do limite. No limite, a API responde 429 até a renovação, a menos que o plano ofereça excedente e você tenha ligado essa opção em Faturamento.' },
    { question: 'Há garantia de reembolso?', answer: 'Cancelar a renovação pelo painel interrompe as próximas cobranças. Quando o reembolso estiver disponível para a sua assinatura, o painel mostra o valor antes de você confirmar.' },
    { question: 'Como funciona o período de teste?', answer: 'Planos com teste podem ser usados de graça pelos dias indicados, uma vez por conta. Se não cancelar até o fim do teste, a assinatura é cobrada no cartão cadastrado.' },
];

onMounted(async () => {
    try {
        await Promise.all([authStore.fetchProfile(), dashboardStore.fetchPlans()]);
    } finally {
        loading.value = false;
    }
});

function openCheckout(plan: any) {
    checkoutPlan.value = plan;
    showCheckout.value = true;
}

async function onCheckoutSuccess() {
    showCheckout.value = false;
    await authStore.fetchProfile();
}
</script>
