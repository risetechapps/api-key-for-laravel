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
                <h1 class="text-3xl sm:text-4xl font-bold text-slate-900 dark:text-white">Termos de Uso</h1>
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
                    Dúvidas sobre estes termos? Entre em contato pelo nosso canal de suporte.
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
        title: 'Aceitação dos termos',
        paragraphs: [
            'Ao criar uma conta ou utilizar a plataforma, você concorda integralmente com estes Termos de Uso. Caso não concorde com qualquer condição aqui descrita, não utilize o serviço.',
            'Estes termos constituem um acordo entre você (cliente) e a plataforma, regendo o acesso e o uso da API e do painel.',
        ],
    },
    {
        title: 'Descrição do serviço',
        paragraphs: [
            'A plataforma fornece gerenciamento de chaves de API, planos de assinatura, controle de limites de requisições e funcionalidades associadas, conforme o plano contratado.',
            'Reservamo-nos o direito de adicionar, alterar ou descontinuar recursos a qualquer momento, com aviso prévio sempre que possível.',
        ],
    },
    {
        title: 'Cadastro e conta',
        paragraphs: [
            'Você é responsável por fornecer informações verdadeiras e mantê-las atualizadas, bem como por toda atividade realizada em sua conta.',
        ],
        bullets: [
            'Você deve ter capacidade legal para contratar o serviço.',
            'A verificação de e-mail é obrigatória para ativar a conta.',
            'É proibido compartilhar credenciais de acesso com terceiros.',
        ],
    },
    {
        title: 'Chaves de API e segurança',
        paragraphs: [
            'Cada conta recebe uma chave de API exibida uma única vez no momento da geração. A guarda da chave é de sua responsabilidade.',
        ],
        bullets: [
            'Mantenha sua chave em segredo e nunca a exponha em código público.',
            'O uso da chave deve respeitar as origens permitidas configuradas.',
            'Em caso de comprometimento, gere uma nova chave imediatamente.',
            'Atividades suspeitas podem levar à suspensão preventiva da chave.',
        ],
    },
    {
        title: 'Uso aceitável',
        paragraphs: [
            'Você concorda em não utilizar o serviço para fins ilícitos ou que violem direitos de terceiros.',
        ],
        bullets: [
            'Não exceder intencionalmente os limites de requisição do seu plano.',
            'Não tentar contornar mecanismos de autenticação, cobrança ou limites.',
            'Não realizar engenharia reversa, sobrecarga ou ataques à infraestrutura.',
        ],
    },
    {
        title: 'Planos, cobrança e renovação',
        paragraphs: [
            'Os planos definem o limite de requisições, o ciclo de cobrança e as funcionalidades habilitadas. O pagamento é processado pelo gateway integrado.',
            'Após a expiração, o plano pode entrar em um período de tolerância configurável antes da suspensão definitiva do acesso.',
        ],
    },
    {
        title: 'Cancelamento e reembolso',
        paragraphs: [
            'Você pode cancelar a assinatura a qualquer momento; o acesso permanece ativo até o fim do período já pago. Pedidos de reembolso seguem a política vigente e a legislação aplicável.',
        ],
    },
    {
        title: 'Limitação de responsabilidade',
        paragraphs: [
            'O serviço é fornecido "no estado em que se encontra". Na máxima extensão permitida em lei, não nos responsabilizamos por danos indiretos, perda de dados ou lucros cessantes decorrentes do uso ou da indisponibilidade do serviço.',
        ],
    },
    {
        title: 'Alterações nos termos',
        paragraphs: [
            'Podemos atualizar estes termos periodicamente. Mudanças relevantes serão comunicadas, e o uso continuado do serviço após a vigência representa concordância com a versão atualizada.',
        ],
    },
    {
        title: 'Contato',
        paragraphs: [
            'Em caso de dúvidas sobre estes Termos de Uso, utilize os canais de suporte disponibilizados na plataforma.',
        ],
    },
];
</script>
