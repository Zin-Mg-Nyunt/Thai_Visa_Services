<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import JobOrderActions from '@/components/JobOrderActions.vue';
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

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold text-brand-dark">
            {{ t('jobOrders.edit.title') }}
        </h1>
        <JobOrderActions :job-order="jobOrder.data" layout="labelled" />
    </div>

    <JobOrderForm :job-order="jobOrder.data" />
</template>
