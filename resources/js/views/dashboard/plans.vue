<template>
    <div class="space-y-6">
        <!-- Current Plan Banner -->
        <Card v-if="currentPlan">
            <div class="flex flex-col md:flex-row items-center justify-between gap-6">
                <div class="flex items-center gap-4">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center shadow-lg shadow-indigo-500/30">
                        <PhCrown :size="28" weight="fill" class="text-white" />
                    </div>
                    <div>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Plano Atual</p>
                        <h2 class="text-xl font-bold text-slate-900 dark:text-white">{{ currentPlan.name }}</h2>
                        <p class="text-sm text-slate-600 dark:text-slate-400">{{ currentPlan.description }}</p>
                    </div>
                </div>

                <div class="flex items-center gap-8 text-center">
                    <div>
                        <p class="text-3xl font-bold text-slate-900 dark:text-white">{{ currentPlan.request_limit.toLocaleString() }}</p>
                        <p class="text-sm text-slate-500 dark:text-slate-400">requisições/mês</p>
                    </div>
                    <div class="w-px h-12 bg-slate-200 dark:bg-slate-700"></div>
                    <div>
                        <p class="text-3xl font-bold text-slate-900 dark:text-white">{{ currentPlan.price }}</p>
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ billingCycleLabel }}</p>
                    </div>
                </div>
            </div>
        </Card>

        <!-- Available Plans -->
        <div class="text-center mb-8">
            <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Escolha seu plano</h2>
            <p class="mt-2 text-slate-600 dark:text-slate-400">Escalabilidade conforme suas necessidades</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <Card
                v-for="plan in availablePlans"
                :key="plan.id"
                :class="[
                    'relative overflow-hidden',
                    plan.is_current ? 'ring-2 ring-indigo-500 dark:ring-indigo-400' : '',
                    plan.is_recommended ? 'bg-gradient-to-b from-indigo-50/50 to-white dark:from-indigo-900/20 dark:to-slate-800' : '',
                ]"
            >
                <!-- Recommended badge -->
                <div
                    v-if="plan.is_recommended"
                    class="absolute top-0 right-0 bg-indigo-500 text-white text-xs font-semibold px-4 py-1 rounded-bl-xl"
                >
                    RECOMENDADO
                </div>

                <div class="space-y-6">
                    <div>
                        <h3 class="text-xl font-bold text-slate-900 dark:text-white">{{ plan.name }}</h3>
                        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">{{ plan.description }}</p>
                    </div>

                    <div class="flex items-baseline gap-1">
                        <span class="text-4xl font-bold text-slate-900 dark:text-white">{{ plan.price }}</span>
                        <span class="text-slate-500 dark:text-slate-400">/{{ billingCycleShort(plan.billing_cycle) }}</span>
                    </div>

                    <div class="h-px bg-slate-200 dark:bg-slate-700"></div>

                    <ul class="space-y-3">
                        <li class="flex items-center gap-3">
                            <PhCheckCircle :size="20" weight="fill" class="text-emerald-500" />
                            <span class="text-sm text-slate-700 dark:text-slate-300">
                                <strong>{{ plan.request_limit.toLocaleString() }}</strong> requisições/mês
                            </span>
                        </li>
                        <li v-for="feature in planBullets(plan)" :key="feature" class="flex items-center gap-3">
                            <PhCheckCircle :size="20" weight="fill" class="text-emerald-500" />
                            <span class="text-sm text-slate-700 dark:text-slate-300">{{ feature }}</span>
                        </li>
                    </ul>

                    <Button
                        variant="primary"
                        class="w-full"
                        :disabled="plan.is_current"
                        @click="openCheckout(plan)"
                    >
                        {{ plan.is_current ? 'Plano Atual' : 'Assinar Agora' }}
                    </Button>
                </div>
            </Card>
        </div>

        <!-- Modal de Checkout -->
        <CheckoutModal
            v-model="showCheckout"
            :plan="checkoutPlan"
            @success="onCheckoutSuccess"
        />

        <!-- FAQ -->
        <Card class="mt-12">
            <template #header>
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Perguntas Frequentes</h3>
            </template>

            <div class="space-y-4">
                <div v-for="faq in faqs" :key="faq.question" class="border-b border-slate-200 dark:border-slate-700 last:border-0 pb-4 last:pb-0">
                    <button
                        @click="faq.isOpen = !faq.isOpen"
                        class="flex items-center justify-between w-full text-left py-2"
                    >
                        <span class="font-medium text-slate-900 dark:text-white">{{ faq.question }}</span>
                        <PhCaretDown
                            :size="20"
                            class="text-slate-400 transition-transform"
                            :class="{ 'rotate-180': faq.isOpen }"
                        />
                    </button>
                    <Transition
                        enter="transition-all duration-200"
                        enter-from="opacity-0 max-h-0"
                        enter-to="opacity-100 max-h-96"
                        leave="transition-all duration-200"
                        leave-from="opacity-100 max-h-96"
                        leave-to="opacity-0 max-h-0"
                    >
                        <p v-if="faq.isOpen" class="text-sm text-slate-600 dark:text-slate-400 mt-2">
                            {{ faq.answer }}
                        </p>
                    </Transition>
                </div>
            </div>
        </Card>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { useDashboardStore } from '@/stores/dashboard';
import {
    PhCrown,
    PhCheckCircle,
    PhCaretDown,
} from '@phosphor-icons/vue';
import Card from "@/views/componentes/Card.vue";
import Button from "@/views/componentes/Button.vue";
import CheckoutModal from "@/views/dashboard/CheckoutModal.vue";

const authStore = useAuthStore();
const dashboardStore = useDashboardStore();

const loading = ref(true);
const showCheckout = ref(false);
const checkoutPlan = ref(null);

const user = computed(() => authStore.user);
const currentPlan = computed(() => user.value?.active_plan?.plan);
const plans = computed(() => dashboardStore.plans);

const billingCycleLabel = computed(() => {
    const cycle = currentPlan.value?.billing_cycle;
    if (cycle === 'monthly') return '/mês';
    if (cycle === 'yearly') return '/ano';
    if (cycle === 'annually') return '/ano';
    return '';
});

const availablePlans = computed(() => {
    return plans.value.map((plan) => ({
        ...plan,
        is_current: plan.id === currentPlan.value?.id,
        is_recommended: plan.code === 'pro',
    }));
});


const faqs = ref([
    {
        question: 'Posso mudar de plano a qualquer momento?',
        answer: 'Sim! Você pode fazer upgrade ou downgrade do seu plano a qualquer momento. As alterações serão aplicadas no próximo ciclo de faturamento.',
        isOpen: false,
    },
    {
        question: 'O que acontece se eu exceder o limite de requisições?',
        answer: 'Quando você atingir 80% do limite, enviaremos um alerta. Ao atingir 100%, as requisições serão temporariamente bloqueadas até a renovação do seu plano ou upgrade.',
        isOpen: false,
    },
    {
        question: 'Há garantia de reembolso?',
        answer: 'Sim, oferecemos garantia de 7 dias para novas assinaturas. Se não estiver satisfeito, cancele dentro deste período para reembolso integral.',
        isOpen: false,
    },
    {
        question: 'Preciso de cartão de crédito para testar?',
        answer: 'Não! Comece com o plano gratuito sem necessidade de cartão. Faça upgrade apenas quando precisar de mais requisições.',
        isOpen: false,
    },
]);

onMounted(async () => {
    await Promise.all([
        authStore.fetchProfile(),
        dashboardStore.fetchPlans(),
    ]);
    loading.value = false;
});

function planBullets(plan) {
    if (plan.features?.length) return plan.features.map(f => f.name ?? f.key ?? f);
    if (plan.features_description?.length) return plan.features_description;
    return [];
}

function billingCycleShort(cycle) {
    if (cycle === 'weekly') return 'semana';
    if (cycle === 'monthly') return 'mês';
    if (cycle === 'yearly') return 'ano';
    if (cycle === 'annually') return 'ano';
    return cycle;
}

function openCheckout(plan) {
    checkoutPlan.value = plan;
    showCheckout.value = true;
}

async function onCheckoutSuccess() {
    showCheckout.value = false;
    await authStore.fetchProfile();
}
</script>
