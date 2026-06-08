<template>
    <section class="py-20 px-4 sm:px-6 lg:px-8 bg-slate-50 dark:bg-slate-900 min-h-screen">
        <div class="max-w-3xl mx-auto">
            <!-- Header -->
            <div class="mb-12">
                <router-link
                    to="/"
                    class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors mb-6">
                    <PhArrowLeft :size="16" />
                    Voltar para o início
                </router-link>
                <h1 class="text-3xl sm:text-4xl font-bold text-slate-900 dark:text-white">Política de Privacidade</h1>
                <p class="mt-3 text-slate-500 dark:text-slate-400">Última atualização: {{ lastUpdated }}</p>
            </div>

            <!-- Content -->
            <div class="space-y-10">
                <div
                    v-for="(section, i) in sections"
                    :key="section.title"
                    :id="`secao-${i + 1}`">
                    <h2 class="text-xl font-bold text-slate-900 dark:text-white mb-3">
                        {{ i + 1 }}. {{ section.title }}
                    </h2>
                    <div class="space-y-3">
                        <p
                            v-for="(p, j) in section.paragraphs"
                            :key="j"
                            class="text-slate-600 dark:text-slate-300 leading-relaxed">
                            {{ p }}
                        </p>
                        <ul
                            v-if="section.bullets"
                            class="list-disc list-inside space-y-1.5 text-slate-600 dark:text-slate-300 pl-1">
                            <li v-for="(b, k) in section.bullets" :key="k">{{ b }}</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Footer note -->
            <div class="mt-12 p-5 rounded-2xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Para exercer seus direitos como titular de dados ou tirar dúvidas, fale com nosso
                    <router-link to="/support" class="text-indigo-600 dark:text-indigo-400 hover:underline">suporte</router-link>.
                </p>
            </div>
        </div>
    </section>
</template>

<script setup lang="ts">
import { PhArrowLeft } from '@phosphor-icons/vue';

const lastUpdated = '7 de junho de 2026';

const sections = [
    {
        title: 'Introdução',
        paragraphs: [
            'Esta Política de Privacidade descreve como coletamos, usamos, armazenamos e protegemos seus dados pessoais ao utilizar a plataforma, em conformidade com a Lei Geral de Proteção de Dados (LGPD - Lei 13.709/2018).',
            'Ao utilizar o serviço, você concorda com as práticas descritas neste documento.',
        ],
    },
    {
        title: 'Dados que coletamos',
        paragraphs: [
            'Coletamos apenas os dados necessários para operar o serviço e cumprir obrigações legais:',
        ],
        bullets: [
            'Dados de cadastro: nome, e-mail e, quando aplicável, documentos de identificação.',
            'Dados de cobrança: processados pelo gateway de pagamento; não armazenamos o número completo do cartão.',
            'Dados de uso: registros de requisições à API (endpoint, método, status e data/hora).',
            'Dados técnicos: endereço IP e origem das requisições, para segurança e validação de acesso.',
        ],
    },
    {
        title: 'Como usamos os dados',
        paragraphs: [
            'Utilizamos seus dados para as seguintes finalidades:',
        ],
        bullets: [
            'Autenticar o acesso e gerenciar sua conta e chave de API.',
            'Processar pagamentos e controlar assinaturas e limites de uso.',
            'Enviar comunicações essenciais (verificação de e-mail, expiração de plano, redefinição de senha).',
            'Prevenir fraudes, abusos e garantir a segurança da plataforma.',
        ],
    },
    {
        title: 'Base legal',
        paragraphs: [
            'O tratamento dos seus dados se fundamenta na execução do contrato (prestação do serviço), no cumprimento de obrigações legais e regulatórias, e no legítimo interesse para segurança e prevenção de fraudes.',
        ],
    },
    {
        title: 'Compartilhamento de dados',
        paragraphs: [
            'Não vendemos seus dados pessoais. O compartilhamento ocorre apenas com prestadores essenciais à operação:',
        ],
        bullets: [
            'Gateway de pagamento, para processar transações.',
            'Provedores de infraestrutura e e-mail, sob obrigações de confidencialidade.',
            'Autoridades, quando exigido por lei ou ordem judicial.',
        ],
    },
    {
        title: 'Cookies',
        paragraphs: [
            'Utilizamos cookies e armazenamento local estritamente necessários para autenticação, manutenção de sessão e preferências (como o tema claro/escuro). Eles não são usados para rastreamento publicitário.',
        ],
    },
    {
        title: 'Segurança',
        paragraphs: [
            'Adotamos medidas técnicas e organizacionais para proteger seus dados. As senhas e chaves de API são armazenadas com hash (bcrypt) e nunca em texto plano. Ainda assim, nenhum sistema é completamente imune; recomendamos boas práticas na guarda das suas credenciais.',
        ],
    },
    {
        title: 'Retenção',
        paragraphs: [
            'Mantemos seus dados pelo tempo necessário às finalidades descritas e ao cumprimento de obrigações legais. Após esse período, os dados são eliminados ou anonimizados de forma segura.',
        ],
    },
    {
        title: 'Seus direitos',
        paragraphs: [
            'Como titular de dados, você pode, a qualquer momento:',
        ],
        bullets: [
            'Confirmar a existência de tratamento e acessar seus dados.',
            'Corrigir dados incompletos, inexatos ou desatualizados.',
            'Solicitar a anonimização, bloqueio ou eliminação de dados desnecessários.',
            'Solicitar a portabilidade e revogar consentimentos, quando aplicável.',
        ],
    },
    {
        title: 'Alterações nesta política',
        paragraphs: [
            'Podemos atualizar esta política periodicamente. Mudanças relevantes serão comunicadas, e a data de "última atualização" no topo será revista.',
        ],
    },
    {
        title: 'Contato',
        paragraphs: [
            'Para questões sobre privacidade ou para exercer seus direitos, utilize os canais de suporte disponibilizados na plataforma.',
        ],
    },
];
</script>
