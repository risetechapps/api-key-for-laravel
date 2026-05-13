<template>
    <section id="demo" class="py-24 px-4 sm:px-6 lg:px-8 bg-slate-50 dark:bg-slate-900/50">
        <div class="max-w-6xl mx-auto">
            <div class="text-center mb-16">
                <h2 class="text-3xl sm:text-4xl font-bold text-slate-900 dark:text-white mb-4">Teste agora
                    mesmo</h2>
                <p class="text-lg text-slate-600 dark:text-slate-400">
                    Experimente a API diretamente do navegador
                </p>
            </div>

            <div class="grid lg:grid-cols-2 gap-8">
                <Card>
                    <div class="space-y-4">
                        <div>
                            <label
                                class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Endpoint</label>
                            <select
                                v-model="demo.endpoint"
                                class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-700
                                 bg-white dark:bg-slate-800 text-slate-900 dark:text-white
                                 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 transition-all">
                                <option v-for="ep in demoEndpoints" :key="ep.value" :value="ep.value">
                                    {{ ep.label }}
                                </option>
                            </select>
                        </div>

                        <div
                            v-if="demo.endpoint === 'cnpj' || demo.endpoint === 'cpf' || demo.endpoint === 'zip_code'">
                            <label
                                class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Número</label>
                            <input
                                v-model="demo.param"
                                type="text"
                                :placeholder="getPlaceholder(demo.endpoint)"
                                class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-700
                                bg-white dark:bg-slate-800 text-slate-900 dark:text-white
                                focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 transition-all font-mono"
                            />
                        </div>

                        <Button
                            variant="primary"
                            size="lg"
                            class="w-full"
                            :loading="demo.loading"
                            @click="runDemo">
                            <PhPlay :size="20"/>
                            Executar
                        </Button>
                    </div>
                </Card>

                <Card title="Resposta" subtitle="JSON formatado"  v-if="demo.response">
                    <div class="relative">
                            <pre class="bg-slate-900 rounded-xl p-4 overflow-x-auto">
                                <code class="text-sm font-mono text-emerald-400">
                                    {{ demo.response || '// Clique em "Executar" para ver a resposta' }}
                                </code>
                            </pre>

                        <button
                            v-if="demo.response"
                            @click="copyResponse"
                            class="absolute top-2 right-2 p-2 rounded-lg bg-slate-800 hover:bg-slate-700
                             transition-colors"
                            :title="demo.copied ? 'Copiado!' : 'Copiar'">
                            <PhCheck v-if="demo.copied" :size="16" class="text-emerald-400"/>
                            <PhCopy v-else :size="16" class="text-slate-400"/>
                        </button>
                    </div>
                </Card>
            </div>
        </div>
    </section>
</template>

<script setup lang="ts">

import {PhCheck, PhCopy, PhPlay} from "@phosphor-icons/vue";
import Card from "@/views/componentes/Card.vue";
import {reactive} from "vue";
import Button from "@/views/componentes/Button.vue";
import axios from "axios";
import axiosDefault from "@/bootstrap";

const demo = reactive({
    endpoint: 'cnpj',
    param: '33000167000101',
    loading: false,
    response: '',
    copied: false,
});

const demoEndpoints = [
    {value: 'cnpj', label: 'Consulta CNPJ'},
    {value: 'cpf', label: 'Consulta CPF'},
    {value: 'zip_code', label: 'Consulta CEP'},
    {value: 'banks', label: 'Lista de Bancos'},
    {value: 'countries', label: 'Países'},
];

const getPlaceholder = (endpoint: string) => {
    const placeholders: Record<string, string> = {
        cnpj: '33.000.167/0001-01',
        cpf: '000.000.000-00',
        zip_code: '00000-000',
    };
    return placeholders[endpoint] || '';
}

const runDemo = async () => {
    demo.loading = true;
    try {

        if (demo.endpoint === 'cnpj') {
            demo.response = await demoCNPJ(demo.param);
        }
        // Simulate API call
        // await new Promise((resolve) => setTimeout(resolve, 800));
        //
        // const responses: Record<string, string> = {
        //     cnpj: JSON.stringify({
        //         cnpj: '33.000.167/0001-01',
        //         razao_social: 'Empresa Exemplo LTDA',
        //         nome_fantasia: 'Exemplo',
        //         situacao: 'ATIVA',
        //         data_inicio_atividade: '2010-01-01',
        //         cnae_fiscal: '6201500',
        //         endereco: {
        //             logradouro: 'Rua Exemplo',
        //             numero: '100',
        //             bairro: 'Centro',
        //             municipio: 'São Paulo',
        //             uf: 'SP',
        //             cep: '01000-000',
        //         },
        //     }, null, 2),
        //     cpf: JSON.stringify({
        //         cpf: '000.000.000-00',
        //         nome: 'João Silva',
        //         situacao: 'REGULAR',
        //         nascimento: '1990-01-01',
        //     }, null, 2),
        //     zip_code: JSON.stringify({
        //         cep: '01000-000',
        //         logradouro: 'Praça da Sé',
        //         complemento: 'lado ímpar',
        //         bairro: 'Sé',
        //         localidade: 'São Paulo',
        //         uf: 'SP',
        //         ibge: '3550308',
        //     }, null, 2),
        //     banks: JSON.stringify([
        //         {code: '001', name: 'Banco do Brasil'},
        //         {code: '104', name: 'Caixa Econômica Federal'},
        //         {code: '033', name: 'Santander'},
        //     ], null, 2),
        //     countries: JSON.stringify([
        //         {code: 'BR', name: 'Brasil'},
        //         {code: 'US', name: 'Estados Unidos'},
        //         {code: 'AR', name: 'Argentina'},
        //     ], null, 2),
        // };

        // demo.response = responses[demo.endpoint] || '{}';
    } finally {
        demo.loading = false;
    }
}

const copyResponse = async () => {
    try {
        await navigator.clipboard.writeText(demo.response);
        demo.copied = true;
        setTimeout(() => demo.copied = false, 2000);
    } catch (err) {
        console.error('Failed to copy:', err);
    }
}

const demoCNPJ = async (params: string) => {

    try{
        const response = await axiosDefault.get('https://orchestrator.risetech.dev.br/api/v1/services/cnpj/' + params);

        console.log(response);
        return {}
        // if(response.success){
        //     return response
        // }
    }catch (e){
        return {}
    }
}


</script>

<style scoped>

</style>
