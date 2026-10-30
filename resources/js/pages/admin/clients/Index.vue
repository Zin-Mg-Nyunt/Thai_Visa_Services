<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/format';
import { create, index, show } from '@/routes/admin/clients';
import type { PaginatedClients } from '@/types';

const props = defineProps<{
    clients: PaginatedClients;
    search: string;
}>();

const { t } = useI18n();

const query = ref(props.search);

watchDebounced(
    query,
    (value) => {
        router.get(
            index.url(),
            { q: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    },
    { debounce: 300 },
);
</script>

<template>
    <Head :title="t('clients.title')" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold text-brand-dark">
            {{ t('clients.title') }}
        </h1>
        <Button as-child variant="pill">
            <Link :href="create()" data-test="new-client-link">{{
                t('clients.new')
            }}</Link>
        </Button>
    </div>

    <section class="space-y-4 rounded-2xl border bg-card p-4 shadow-xs md:p-6">
        <Input
            v-model="query"
            type="search"
            class="h-11 rounded-xl"
            :placeholder="t('clients.searchPlaceholder')"
            :aria-label="t('common.search')"
            data-test="client-search"
        />

        <div v-if="clients.data.length" class="overflow-x-auto">
            <table class="w-full min-w-xl text-left text-sm">
                <thead
                    class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    <tr class="border-b">
                        <th class="px-3 py-3">
                            {{ t('clients.columns.name') }}
                        </th>
                        <th class="px-3 py-3">
                            {{ t('clients.columns.phone') }}
                        </th>
                        <th class="px-3 py-3">
                            {{ t('clients.columns.birthday') }}
                        </th>
                        <th class="px-3 py-3">
                            {{ t('clients.columns.gender') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="client in clients.data"
                        :key="client.id"
                        class="border-b last:border-0"
                    >
                        <td class="px-3 py-3 font-medium text-brand-dark">
                            <Link
                                :href="show(client.id)"
                                class="hover:underline"
                            >
                                {{ client.name }}
                            </Link>
                        </td>
                        <td class="px-3 py-3 whitespace-nowrap">
                            {{ client.phone }}
                        </td>
                        <td class="px-3 py-3 whitespace-nowrap">
                            {{ formatDate(client.birthday) }}
                        </td>
                        <td class="px-3 py-3">
                            {{ t(`clients.gender.${client.gender}`) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else class="py-8 text-center text-muted-foreground">
            {{ t('clients.empty') }}
        </p>

        <Pagination :meta="clients.meta" :links="clients.links" />
    </section>
</template>
