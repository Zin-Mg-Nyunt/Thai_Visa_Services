<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import ClientServiceForm from '@/components/ClientServiceForm.vue';
import { pageTitle } from '@/lib/pageTitle';
import type { Client, ServiceOption } from '@/types';

defineProps<{
    client: { data: Client };
    serviceOptions: ServiceOption[];
}>();

const { t } = useI18n();
</script>

<template>
    <Head :title="pageTitle('clientServices.create.head')" />

    <h1 class="text-2xl font-bold text-brand-dark">
        {{ t('clientServices.create.title') }}
    </h1>
    <p class="mt-1 mb-6 text-sm break-words text-muted-foreground">
        {{ client.data.name }}
    </p>

    <ClientServiceForm
        :client="client.data"
        :service-options="serviceOptions"
    />
</template>
