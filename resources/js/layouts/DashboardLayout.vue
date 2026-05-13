<template>
    <div class="min-h-screen bg-slate-50 dark:bg-slate-900 flex">
        <!-- Sidebar -->
        <aside
            class="w-72 bg-white dark:bg-slate-900 border-r border-slate-200 dark:border-slate-800
            flex-shrink-0 lg:block hidden lg:h-screen lg:sticky lg:top-0 lg:overflow-y-auto">
            <Sidebar :is-open="true" @close="sidebarOpen = false" />
        </aside>

        <!-- Mobile Sidebar Overlay -->
        <div
            v-if="sidebarOpen"
            class="fixed inset-0 bg-slate-900/50 z-40 lg:hidden"
            @click="sidebarOpen = false"
        />

        <!-- Mobile Sidebar -->
        <aside
            :class="[
                'fixed inset-y-0 left-0 z-50 w-72 bg-white dark:bg-slate-900 border-r border-slate-200 ' +
                 'dark:border-slate-800',
                'lg:hidden',
                sidebarOpen ? 'translate-x-0' : '-translate-x-full'
            ]">
            <Sidebar :is-open="true" @close="sidebarOpen = false" />
        </aside>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col min-w-0">
            <Header @toggle-sidebar="sidebarOpen = !sidebarOpen" />

            <main class="flex-1 p-4 sm:p-6 lg:p-8">
                <div class="max-w-7xl mx-auto">
                    <router-view />
                </div>
            </main>

        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import Sidebar from '@/views/dashboard/layout/Sidebar.vue';
import Header from '@/views/dashboard/layout/Header.vue';
import Footer from '@/views/dashboard/layout/Footer.vue';
import { useAuthStore } from '@/stores/auth';

const authStore = useAuthStore();
const sidebarOpen = ref(false);

onMounted(async () => {
    await authStore.initializeAuth();
});
</script>
