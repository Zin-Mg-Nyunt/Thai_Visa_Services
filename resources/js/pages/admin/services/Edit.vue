<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import ServiceForm from '@/components/ServiceForm.vue';
import { pageTitle } from '@/lib/pageTitle';
import type { ParentOption, Service } from '@/types';

defineProps<{
    service: { data: Service };
    parentOptions: ParentOption[];
}>();

const { t } = useI18n();
</script>

<template>
    <Head :title="pageTitle('services.edit.head')" />

    <h1 class="mb-6 text-2xl font-bold text-brand-dark">
        {{ t('services.edit.title') }}
    </h1>

    <ServiceForm :service="service.data" :parent-options="parentOptions" />
</template>
