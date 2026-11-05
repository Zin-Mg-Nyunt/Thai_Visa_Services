<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import ServiceForm from '@/components/ServiceForm.vue';
import { pageTitle } from '@/lib/pageTitle';
import type { ParentOption } from '@/types';

defineProps<{
    parentOptions: ParentOption[];
    defaultParentId: number | null;
}>();

const { t } = useI18n();
</script>

<template>
    <Head :title="pageTitle('services.create.head')" />

    <h1 class="mb-6 text-2xl font-bold text-brand-dark">
        {{
            defaultParentId === null
                ? t('services.create.title')
                : t('services.create.subTitle')
        }}
    </h1>

    <ServiceForm
        :parent-options="parentOptions"
        :default-parent-id="defaultParentId"
    />
</template>
