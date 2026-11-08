<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Cake, Phone, Plus, Search, UserRound } from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDate } from '@/lib/format';
import { create, index, show } from '@/routes/admin/clients';
import { localised } from '@/lib/service';
import type { PaginatedClients, ServiceFilterOption } from '@/types';
import { pageTitle } from '@/lib/pageTitle';

const props = defineProps<{
    clients: PaginatedClients;
    search: string;
    filters: {
        service?: number;
        sub_service?: number | 'none';
        status?: string;
        payment?: 'fully_paid' | 'remaining';
    };
    serviceOptions: ServiceFilterOption[];
}>();

const { t } = useI18n();

const query = ref(props.search);

// 'all' stands for "no filter": the select cannot hold an empty value.
const chosen = reactive({
    service: props.filters.service ? String(props.filters.service) : 'all',
    subService: props.filters.sub_service
        ? String(props.filters.sub_service)
        : 'all',
    status: props.filters.status ?? 'all',
    payment: props.filters.payment ?? 'all',
});

const statuses = ['in_progress', 'complete', 'cancel'] as const;
const statusKey = {
    in_progress: 'inProgress',
    complete: 'complete',
    cancel: 'cancel',
} as const;

const titleOf = (service: { title: string | null; title_my: string | null }) =>
    localised(service.title, service.title_my) || t('services.untitled');

const subServiceOptions = computed(
    () =>
        props.serviceOptions.find(
            (service) => String(service.id) === chosen.service,
        )?.sub_services ?? [],
);

const hasFilters = computed(
    () =>
        chosen.service !== 'all' ||
        chosen.subService !== 'all' ||
        chosen.status !== 'all' ||
        chosen.payment !== 'all',
);

const pick = (value: string) => (value === 'all' ? undefined : value);

const visit = () => {
    router.get(
        index.url(),
        {
            q: query.value || undefined,
            service: pick(chosen.service),
            sub_service: pick(chosen.subService),
            status: pick(chosen.status),
            payment: pick(chosen.payment),
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const chooseService = (value: unknown) => {
    chosen.service = String(value);
    chosen.subService = 'all';
};

const clearFilters = () => {
    chosen.service = 'all';
    chosen.subService = 'all';
    chosen.status = 'all';
    chosen.payment = 'all';
};

watchDebounced(query, visit, { debounce: 300 });
watch(chosen, visit);
</script>

<template>
    <Head :title="pageTitle('clients.title')" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold text-brand-dark">
            {{ t('clients.title') }}
        </h1>
        <Button as-child variant="pill">
            <Link
                :href="create()"
                :aria-label="t('clients.new')"
                :title="t('clients.new')"
                data-test="new-client-link"
            >
                <Plus class="size-4" />
                <span class="hidden sm:inline">{{ t('clients.new') }}</span>
            </Link>
        </Button>
    </div>

    <section class="space-y-4 rounded-2xl border bg-card p-4 shadow-xs md:p-6">
        <div class="relative">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                v-model="query"
                type="search"
                class="h-11 rounded-xl pl-10"
                :placeholder="t('clients.searchPlaceholder')"
                :aria-label="t('common.search')"
                data-test="client-search"
            />
        </div>

        <div
            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
            data-test="client-filters"
        >
            <Select
                :model-value="chosen.service"
                @update:model-value="chooseService"
            >
                <SelectTrigger
                    class="w-full"
                    :aria-label="t('clients.filters.service')"
                    data-test="filter-service"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">
                        {{ t('clients.filters.allServices') }}
                    </SelectItem>
                    <SelectItem
                        v-for="service in serviceOptions"
                        :key="service.id"
                        :value="String(service.id)"
                    >
                        {{ titleOf(service) }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <Select
                v-model="chosen.subService"
                :disabled="chosen.service === 'all'"
            >
                <SelectTrigger
                    class="w-full"
                    :aria-label="t('clients.filters.subService')"
                    data-test="filter-sub-service"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">
                        {{ t('clients.filters.allSubServices') }}
                    </SelectItem>
                    <SelectItem value="none">
                        {{ t('clientServices.mainOnly') }}
                    </SelectItem>
                    <SelectItem
                        v-for="sub in subServiceOptions"
                        :key="sub.id"
                        :value="String(sub.id)"
                    >
                        {{ titleOf(sub) }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <Select v-model="chosen.status">
                <SelectTrigger
                    class="w-full"
                    :aria-label="t('clients.filters.status')"
                    data-test="filter-status"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">
                        {{ t('clients.filters.anyStatus') }}
                    </SelectItem>
                    <SelectItem
                        v-for="status in statuses"
                        :key="status"
                        :value="status"
                    >
                        {{ t(`serviceStatus.${statusKey[status]}`) }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <Select v-model="chosen.payment">
                <SelectTrigger
                    class="w-full"
                    :aria-label="t('clients.filters.payment')"
                    data-test="filter-payment"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">
                        {{ t('clients.filters.anyPayment') }}
                    </SelectItem>
                    <SelectItem value="fully_paid">
                        {{ t('paymentStatus.fullyPaid') }}
                    </SelectItem>
                    <SelectItem value="remaining">
                        {{ t('clients.filters.remaining') }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div v-if="hasFilters" class="flex justify-end">
            <Button
                variant="pillOutline"
                size="sm"
                data-test="clear-client-filters"
                @click="clearFilters"
            >
                {{ t('clients.filters.clear') }}
            </Button>
        </div>

        <ul v-if="clients.data.length" class="space-y-3 md:hidden">
            <li
                v-for="client in clients.data"
                :key="client.id"
                class="rounded-xl border p-4 text-sm"
            >
                <Link
                    :href="show(client.id)"
                    class="block font-semibold wrap-break-word text-brand-dark hover:underline"
                >
                    {{ client.name }}
                </Link>
                <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                    <dt class="flex items-center gap-1.5 text-muted-foreground">
                        <Phone class="size-3.5" />
                        {{ t('clients.columns.phone') }}
                    </dt>
                    <dd class="text-right break-all">{{ client.phone }}</dd>
                    <dt class="flex items-center gap-1.5 text-muted-foreground">
                        <Cake class="size-3.5" />
                        {{ t('clients.columns.birthday') }}
                    </dt>
                    <dd class="text-right">
                        {{ formatDate(client.birthday) }}
                    </dd>
                    <dt class="flex items-center gap-1.5 text-muted-foreground">
                        <UserRound class="size-3.5" />
                        {{ t('clients.columns.gender') }}
                    </dt>
                    <dd class="text-right">
                        {{ t(`clients.gender.${client.gender}`) }}
                    </dd>
                </dl>
            </li>
        </ul>

        <div v-if="clients.data.length" class="hidden overflow-x-auto md:block">
            <table class="w-full text-left text-sm">
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
