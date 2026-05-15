<template>
    <footer class="py-6 px-4 sm:px-6 lg:px-8 border-t border-slate-200 dark:border-slate-800">
        <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
            <p class="text-sm text-slate-500 dark:text-slate-400">
                © {{ currentYear }} Api Key. Todos os direitos reservados.
            </p>
            <div class="flex items-center gap-6">
                <a href="#" class="text-sm text-slate-500 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">
                    Documentação
                </a>
                <a href="#" class="text-sm text-slate-500 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">
                    Suporte
                </a>
                <a href="#" class="text-sm text-slate-500 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">
                    Status
                </a>
            </div>
        </div>
    </footer>
</template>

<script setup>
const currentYear = new Date().getFullYear();
</script>
