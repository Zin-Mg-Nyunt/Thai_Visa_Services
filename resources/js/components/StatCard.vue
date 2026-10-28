<script setup lang="ts">
import type { Component } from 'vue';

defineProps<{
    label: string;
    value: string | number;
    icon?: Component;
}>();
</script>

<template>
    <div
        class="flex items-start justify-between gap-4 rounded-2xl border bg-card p-5 shadow-xs"
    >
        <div class="min-w-0 space-y-1">
            <p class="text-sm text-muted-foreground">{{ label }}</p>
            <p class="truncate text-2xl font-bold text-brand-dark">
                {{ value }}
            </p>
            <slot />
        </div>
        <div
            v-if="icon"
            class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-mint text-brand-dark"
        >
            <component :is="icon" class="size-5" />
        </div>
    </div>
</template>
