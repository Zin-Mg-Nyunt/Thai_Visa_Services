<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import ClientForm from '@/components/ClientForm.vue';
import { pageTitle } from '@/lib/pageTitle';

const { t } = useI18n();
</script>

<template>
    <Head :title="pageTitle('clients.create.head')" />

    <h1 class="mb-6 text-2xl font-bold text-brand-dark">
        {{ t('clients.create.title') }}
    </h1>

    <ClientForm />
</template>
