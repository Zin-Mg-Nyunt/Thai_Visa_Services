<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

withDefaults(
    defineProps<{
        variant?: 'dark' | 'white';
    }>(),
    {
        variant: 'white',
    },
);

const name = usePage().props.name;
</script>

<template>
    <AppLogoIcon :variant="variant" class="size-8 rounded-md" />
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate leading-tight font-semibold">{{
            name
        }}</span>
    </div>
</template>
