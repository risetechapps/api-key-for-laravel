<template>
    <div>
        <PageHeader title="Saúde do sistema" subtitle="Gateway, webhooks, rotinas agendadas, fila e pagamentos parados" :icon="PhHeartbeat">
            <template #actions>
                <span v-if="checkedAt" class="text-xs text-slate-400">conferido {{ format.relative(checkedAt) }}</span>
                <Button variant="outline" :icon="PhArrowsClockwise" :loading="loading" @click="load">Atualizar</Button>
            </template>
        </PageHeader>

        <div v-if="!report" class="space-y-4"><Skeleton height="5rem" rounded="rounded-2xl" /><Skeleton height="14rem" rounded="rounded-2xl" /></div>

        <template v-else>
            <div :class="['relative mb-8 flex items-center gap-4 overflow-hidden rounded-2xl p-5', banner[report.status].cls]">
                <span class="relative flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-white/20">
                    <span v-if="report.status === 'ok'" class="absolute inset-0 animate-ping rounded-2xl bg-white/20 [animation-duration:2.5s]" />
                    <component :is="banner[report.status].icon" :size="26" weight="fill" />
                </span>
                <div>
                    <p class="text-lg font-bold">{{ banner[report.status].title }}</p>
                    <p class="text-sm opacity-90">{{ counts.ok }} ok · {{ counts.warning }} para conferir · {{ counts.error }} com problema</p>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <Card v-for="(checks, group) in groups" :key="group" :title="String(group)">
                    <ul class="space-y-1">
                        <li v-for="c in checks" :key="c.label" class="flex items-start gap-3 rounded-xl p-2.5 -mx-2.5 transition-colors hover:bg-slate-50 dark:hover:bg-slate-800/40">
                            <span :class="['mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full', icon[c.status].cls]">
                                <component :is="icon[c.status].icon" :size="13" weight="bold" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-slate-900 dark:text-white">{{ c.label }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ c.detail }}</p>
                            </div>
                        </li>
                    </ul>
                </Card>
            </div>
        </template>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import { PhHeartbeat, PhArrowsClockwise, PhCheckCircle, PhWarning, PhXCircle, PhCheck, PhExclamationMark, PhX } from '@phosphor-icons/vue';
import { Button, Card, PageHeader, Skeleton, errorMessage, format, toast } from '@/ui';

interface Check { group: string; label: string; status: 'ok' | 'warning' | 'error'; detail: string }

const report = ref<{ status: 'ok' | 'warning' | 'error'; checks: Check[] } | null>(null);
const loading = ref(true);
const checkedAt = ref<Date | null>(null);

const banner = {
    ok: { title: 'Tudo funcionando', icon: PhCheckCircle, cls: 'bg-gradient-to-r from-emerald-500 to-emerald-600 text-white' },
    warning: { title: 'Há pontos para conferir', icon: PhWarning, cls: 'bg-gradient-to-r from-amber-500 to-amber-600 text-white' },
    error: { title: 'Há problemas que afetam cobrança ou acesso', icon: PhXCircle, cls: 'bg-gradient-to-r from-red-500 to-red-600 text-white' },
};

const icon = {
    ok: { icon: PhCheck, cls: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300' },
    warning: { icon: PhExclamationMark, cls: 'bg-amber-100 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300' },
    error: { icon: PhX, cls: 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-300' },
};

const groups = computed(() => {
    const out: Record<string, Check[]> = {};
    for (const c of report.value?.checks ?? []) (out[c.group] ??= []).push(c);
    return out;
});

const counts = computed(() => {
    const c = { ok: 0, warning: 0, error: 0 };
    for (const check of report.value?.checks ?? []) c[check.status]++;
    return c;
});

async function load() {
    loading.value = true;
    try {
        const r = await axios.get('/dashboard/admin/health');
        report.value = r.data?.data;
        checkedAt.value = new Date();
    } catch (err) {
        toast.error('Não foi possível conferir', { description: errorMessage(err) });
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>
