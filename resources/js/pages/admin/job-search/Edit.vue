<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import JobOrderForm from '@/components/JobOrderForm.vue';
import { pageTitle } from '@/lib/pageTitle';
import type { JobOrder } from '@/types';

defineProps<{
    jobOrder: { data: JobOrder };
}>();

const { t } = useI18n();
</script>

<template>
    <Head :title="pageTitle('jobOrders.edit.head')" />

    <h1 class="mb-6 text-2xl font-bold text-brand-dark">
        {{ t('jobOrders.edit.title') }}
    </h1>

    <JobOrderForm :job-order="jobOrder.data" />
</template>
