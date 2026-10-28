<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
    section: 'clients' | 'services' | 'jobSearch' | 'settings';
}>();

const { t } = useI18n();
const title = computed(() => t(`nav.${props.section}`));
</script>

<template>
    <Head :title="title" />

    <h1 class="text-2xl font-bold text-brand-dark">{{ title }}</h1>
    <p class="mt-2 text-muted-foreground">{{ t('admin.comingSoon') }}</p>
</template>
