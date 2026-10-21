import './bootstrap.ts';
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import router from './router';
import App from './App.vue';
import VueApexCharts from 'vue3-apexcharts';

// Inicialização do tema (antes de montar a aplicação)
function initializeTheme() {
    // Verifica localStorage primeiro
    const savedTheme = localStorage.getItem('darkMode');

    if (savedTheme !== null) {
        // Usuário tem preferência salva
        if (savedTheme === 'true') {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    } else {
        // Nenhuma preferência salva - usa preferência do sistema
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        if (prefersDark) {
            document.documentElement.classList.add('dark');
            localStorage.setItem('darkMode', 'true');
        } else {
            document.documentElement.classList.remove('dark');
            localStorage.setItem('darkMode', 'false');
        }
    }
}

// Executa imediatamente
initializeTheme();

const pinia = createPinia();
const app = createApp(App);

app.use(pinia);
app.use(router);
app.use(VueApexCharts);
app.mount('#app');
