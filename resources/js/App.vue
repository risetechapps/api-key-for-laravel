<template>
    <router-view />
    <ToastHost />
    <DialogHost />
    <CommandPalette v-if="authStore.isAuthenticated" />
</template>

<script setup lang="ts">
import { ToastHost, DialogHost, CommandPalette } from '@/ui';
import { useAuthStore } from '@/stores/auth';

const authStore = useAuthStore();
</script>
