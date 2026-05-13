<template>
    <router-view />
</template>

<script setup>
</script>

<style>
/* Smooth transitions for dark mode */
* {
    transition-property: background-color, border-color;
    transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
    transition-duration: 150ms;
}
</style>
