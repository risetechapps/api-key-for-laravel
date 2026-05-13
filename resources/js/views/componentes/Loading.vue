<template>
    <div :class="containerClasses" v-if="variant === 'spinner'">
        <svg
            :class="spinnerClasses"
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
        >
            <circle
                class="opacity-25"
                cx="12"
                cy="12"
                r="10"
                stroke="currentColor"
                stroke-width="4"
            ></circle>
            <path
                class="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
            ></path>
        </svg>
    </div>

    <div :class="containerClasses" v-else-if="variant === 'dots'">
        <div class="flex gap-1">
            <div
                v-for="i in 3"
                :key="i"
                class="bg-current rounded-full animate-bounce"
                :class="dotClasses"
                :style="{ animationDelay: `${(i - 1) * 0.1}s` }"
            ></div>
        </div>
    </div>

    <div :class="containerClasses" v-else-if="variant === 'skeleton'">
        <div class="animate-pulse space-y-4 w-full">
            <div v-if="lines" class="space-y-3">
                <div
                    v-for="i in lines"
                    :key="i"
                    class="bg-slate-200 dark:bg-slate-700 rounded-lg"
                    :class="i === lines ? 'w-3/4' : 'w-full'"
                    :style="{ height: `${height}px` }"
                ></div>
            </div>
            <div v-else class="bg-slate-200 dark:bg-slate-700 rounded-lg w-full" :style="{ height: `${height}px` }"></div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    variant: {
        type: String,
        default: 'spinner',
        validator: (v) => ['spinner', 'dots', 'skeleton'].includes(v),
    },
    size: {
        type: String,
        default: 'md',
        validator: (v) => ['sm', 'md', 'lg', 'xl'].includes(v),
    },
    lines: {
        type: Number,
        default: 0,
    },
    height: {
        type: Number,
        default: 16,
    },
    centered: {
        type: Boolean,
        default: true,
    },
});

const containerClasses = computed(() => {
    return props.centered ? 'flex items-center justify-center' : '';
});

const sizeMap = {
    sm: 'w-4 h-4',
    md: 'w-8 h-8',
    lg: 'w-12 h-12',
    xl: 'w-16 h-16',
};

const dotSizeMap = {
    sm: 'w-1 h-1',
    md: 'w-2 h-2',
    lg: 'w-3 h-3',
    xl: 'w-4 h-4',
};

const spinnerClasses = computed(() => {
    return `animate-spin ${sizeMap[props.size]}`;
});

const dotClasses = computed(() => {
    return dotSizeMap[props.size];
});
</script>
