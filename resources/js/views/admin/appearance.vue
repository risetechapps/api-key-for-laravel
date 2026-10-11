<template>
    <div>
        <PageHeader title="Aparência" subtitle="Nome, cor e logo do painel. Vale para todos os usuários, sem recompilar." :icon="PhPaintBrush" />

        <div class="grid gap-6 lg:grid-cols-5">
            <Card class="lg:col-span-3" title="Identidade visual">
                <div v-if="loading" class="space-y-4"><Skeleton height="2.5rem" /><Skeleton height="2.5rem" /><Skeleton height="2.5rem" /></div>
                <form v-else class="space-y-5" @submit.prevent="save">
                    <TextInput v-model="form.name" label="Nome do painel" placeholder="Usa o APP_NAME quando vazio" hint="Aparece no menu, na aba do navegador e nos e-mails do painel." />

                    <Field label="Cor da marca" hint="Gera a paleta inteira: botões, links, destaques e gráficos.">
                        <div class="flex flex-wrap items-center gap-3">
                            <label class="relative h-10 w-14 cursor-pointer overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700 shadow-xs">
                                <input v-model="form.color" type="color" class="absolute -inset-2 h-[calc(100%+1rem)] w-[calc(100%+1rem)] cursor-pointer" @input="preview" />
                            </label>
                            <TextInput v-model="form.color" class="max-w-36" mono placeholder="#6366f1" @update:model-value="preview" />
                            <div class="flex flex-wrap gap-1.5">
                                <button
                                    v-for="c in presets"
                                    :key="c"
                                    type="button"
                                    :class="['h-7 w-7 rounded-full ring-2 ring-offset-2 ring-offset-white dark:ring-offset-slate-900 transition-transform hover:scale-110', form.color.toLowerCase() === c ? 'ring-slate-900 dark:ring-white' : 'ring-transparent']"
                                    :style="{ background: c }"
                                    :aria-label="`Usar a cor ${c}`"
                                    @click="form.color = c; preview()"
                                />
                            </div>
                        </div>
                    </Field>

                    <TextInput v-model="form.logo" label="Logo (URL HTTPS)" placeholder="https://…/logo.svg" hint="Sem logo, o painel mostra o ícone padrão com o nome." />

                    <div class="flex justify-end gap-2 pt-2">
                        <Button variant="ghost" @click="reset">Descartar</Button>
                        <Button type="submit" :loading="saving" :icon="PhCheck">Salvar aparência</Button>
                    </div>
                </form>
            </Card>

            <Card class="lg:col-span-2" title="Pré-visualização" subtitle="Como os componentes ficam com a cor escolhida">
                <div class="space-y-5">
                    <div class="flex items-center gap-3">
                        <img v-if="form.logo" :src="form.logo" alt="" class="h-9 w-auto max-w-40 object-contain" />
                        <span v-else class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 shadow-lg shadow-brand-600/30">
                            <PhHexagon :size="18" weight="fill" class="text-white" />
                        </span>
                        <span class="text-base font-bold text-slate-900 dark:text-white">{{ form.name || 'Dashboard' }}</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button size="sm">Primário</Button>
                        <Button size="sm" variant="soft">Suave</Button>
                        <Button size="sm" variant="outline">Contorno</Button>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Badge tone="brand" dot>Ativo</Badge>
                        <Badge tone="success">Pago</Badge>
                        <Badge tone="warning">Em análise</Badge>
                    </div>
                    <Progress :value="68" label="Uso do plano" show-value />
                    <Tabs v-model="previewTab" :tabs="[{ key: 'a', label: 'Visão geral' }, { key: 'b', label: 'Cobranças', count: 3 }]" :animate="false" />
                </div>
            </Card>
        </div>
    </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import axios from 'axios';
import { PhPaintBrush, PhHexagon, PhCheck } from '@phosphor-icons/vue';
import { Badge, Button, Card, Field, PageHeader, Progress, Skeleton, Tabs, TextInput, errorMessage, toast } from '@/ui';
import { applyBrandColor, brand } from '@/brand';

const presets = ['#6366f1', '#2563eb', '#0891b2', '#059669', '#16a34a', '#d97706', '#dc2626', '#db2777', '#7c3aed', '#0f172a'];

const loading = ref(true);
const saving = ref(false);
const previewTab = ref('a');
const form = ref({ name: '', color: '#6366f1', logo: '' });
const original = ref({ ...form.value });

onMounted(async () => {
    try {
        const r = await axios.get('/dashboard/admin/payment-settings');
        const v = r.data?.data?.values ?? {};
        form.value = { name: v['spa.brand.name'] ?? '', color: v['spa.brand.color'] || brand.color, logo: v['spa.brand.logo'] ?? '' };
        original.value = { ...form.value };
    } catch (err) {
        toast.error('Não foi possível carregar a aparência', { description: errorMessage(err) });
    } finally {
        loading.value = false;
    }
});

function preview() {
    applyBrandColor(form.value.color);
}

function reset() {
    form.value = { ...original.value };
    preview();
}

async function save() {
    saving.value = true;
    try {
        await axios.put('/dashboard/admin/payment-settings', {
            values: {
                'spa.brand.name': form.value.name || null,
                'spa.brand.color': form.value.color,
                'spa.brand.logo': form.value.logo || null,
            },
        });
        original.value = { ...form.value };
        toast.success('Aparência salva', { description: 'Os outros usuários veem a mudança no próximo carregamento.' });
    } catch (err) {
        toast.error('Não foi possível salvar', { description: errorMessage(err) });
    } finally {
        saving.value = false;
    }
}
</script>
