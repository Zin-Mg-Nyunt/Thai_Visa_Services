<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import ClientServiceForm from '@/components/ClientServiceForm.vue';
import { pageTitle } from '@/lib/pageTitle';
import type { Client, ClientService, SubServiceOption } from '@/types';

defineProps<{
    client: { data: Client };
    clientService: { data: ClientService };
    subServiceOptions: SubServiceOption[];
    serviceDefaultFee: string | null;
}>();

const { t } = useI18n();
</script>

<template>
    <Head :title="pageTitle('clientServices.edit.head')" />

    <h1 class="text-2xl font-bold text-brand-dark">
        {{ t('clientServices.edit.title') }}
    </h1>
    <p class="mt-1 mb-6 text-sm break-words text-muted-foreground">
        {{ client.data.name }}
    </p>

    <ClientServiceForm
        :client="client.data"
        :client-service="clientService.data"
        :sub-service-options="subServiceOptions"
        :service-default-fee="serviceDefaultFee"
    />
</template>
