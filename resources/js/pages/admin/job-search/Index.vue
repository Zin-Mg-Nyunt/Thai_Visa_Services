<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Search, TriangleAlert } from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import JobOrderActions from '@/components/JobOrderActions.vue';
import Pagination from '@/components/Pagination.vue';
import StatusBadge from '@/components/StatusBadge.vue';
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
import { pageTitle } from '@/lib/pageTitle';
import { create, index } from '@/routes/admin/job-search';
import type {
    JobOrder,
    JobOrderStatusValue,
    PaginatedJobOrders,
} from '@/types';

const props = defineProps<{
    jobOrders: PaginatedJobOrders;
    search: string;
    filters: { status?: JobOrderStatusValue };
}>();

const { t } = useI18n();

const query = ref(props.search);

// 'all' stands for "no filter": the select cannot hold an empty value.
const chosen = reactive({ status: props.filters.status ?? 'all' });

const statuses: JobOrderStatusValue[] = ['open', 'done', 'cancelled'];

const hasFilters = computed(() => chosen.status !== 'all');

const visit = () => {
    router.get(
        index.url(),
        {
            q: query.value || undefined,
            status: chosen.status === 'all' ? undefined : chosen.status,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const exceedsText = (order: JobOrder) =>
    t('jobOrders.exceeds', {
        count: order.workers_count,
        manpower: order.manpower,
    });

watchDebounced(query, visit, { debounce: 300 });
watch(chosen, visit);
</script>

<template>
    <Head :title="pageTitle('jobOrders.title')" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold text-brand-dark">
            {{ t('jobOrders.title') }}
        </h1>
        <Button as-child variant="pill">
            <Link
                :href="create()"
                :aria-label="t('jobOrders.new')"
                :title="t('jobOrders.new')"
                data-test="new-job-order-link"
            >
                <Plus class="size-4" />
                <span class="hidden sm:inline">{{ t('jobOrders.new') }}</span>
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
                :placeholder="t('jobOrders.searchPlaceholder')"
                :aria-label="t('common.search')"
                data-test="job-order-search"
            />
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <Select v-model="chosen.status">
                <SelectTrigger
                    class="w-full sm:w-56"
                    :aria-label="t('jobOrders.filters.status')"
                    data-test="filter-job-order-status"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">
                        {{ t('jobOrders.filters.anyStatus') }}
                    </SelectItem>
                    <SelectItem
                        v-for="status in statuses"
                        :key="status"
                        :value="status"
                    >
                        {{ t(`jobOrderStatus.${status}`) }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <Button
                v-if="hasFilters"
                variant="pillOutline"
                size="sm"
                data-test="clear-job-order-filters"
                @click="chosen.status = 'all'"
            >
                {{ t('jobOrders.filters.clear') }}
            </Button>
        </div>

        <ul v-if="jobOrders.data.length" class="space-y-3 md:hidden">
            <li
                v-for="order in jobOrders.data"
                :key="order.id"
                class="rounded-xl border p-4 text-sm"
                data-test="job-order-card"
            >
                <div class="flex items-start justify-between gap-3">
                    <p class="font-semibold wrap-break-word text-brand-dark">
                        {{ order.company_name }}
                    </p>
                    <StatusBadge kind="jobOrderStatus" :status="order.status" />
                </div>
                <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                    <dt class="text-muted-foreground">
                        {{ t('jobOrders.columns.manpower') }}
                    </dt>
                    <dd class="text-right">{{ order.manpower }}</dd>
                    <dt class="text-muted-foreground">
                        {{ t('jobOrders.columns.split') }}
                    </dt>
                    <dd class="text-right">
                        {{ order.male_count }} / {{ order.female_count }}
                    </dd>
                    <dt class="text-muted-foreground">
                        {{ t('jobOrders.columns.interview') }}
                    </dt>
                    <dd class="text-right">
                        {{ formatDate(order.interview_date) }}
                    </dd>
                    <dt class="text-muted-foreground">
                        {{ t('jobOrders.columns.start') }}
                    </dt>
                    <dd class="text-right">
                        {{ formatDate(order.start_date) }}
                    </dd>
                    <dt class="text-muted-foreground">
                        {{ t('jobOrders.columns.workers') }}
                    </dt>
                    <dd class="text-right">{{ order.workers_count }}</dd>
                </dl>
                <p
                    v-if="order.exceeds_manpower"
                    class="mt-2 flex items-start gap-2 rounded-lg bg-warning px-2 py-1.5 text-xs text-warning-foreground"
                    data-test="exceeds-manpower-marker"
                >
                    <TriangleAlert class="mt-0.5 size-3.5 shrink-0" />
                    {{ exceedsText(order) }}
                </p>
                <div class="mt-3 flex justify-end">
                    <JobOrderActions :job-order="order" layout="menu" />
                </div>
            </li>
        </ul>

        <div
            v-if="jobOrders.data.length"
            class="hidden overflow-x-auto md:block"
        >
            <table class="w-full text-left text-sm">
                <thead
                    class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    <tr class="border-b">
                        <th class="px-3 py-3">
                            {{ t('jobOrders.columns.company') }}
                        </th>
                        <th class="px-3 py-3">
                            {{ t('jobOrders.columns.manpower') }}
                        </th>
                        <th class="px-3 py-3">
                            {{ t('jobOrders.columns.split') }}
                        </th>
                        <th class="px-3 py-3">
                            {{ t('jobOrders.columns.interview') }}
                        </th>
                        <th class="px-3 py-3">
                            {{ t('jobOrders.columns.start') }}
                        </th>
                        <th class="px-3 py-3">
                            {{ t('jobOrders.columns.workers') }}
                        </th>
                        <th class="px-3 py-3">
                            {{ t('jobOrders.columns.status') }}
                        </th>
                        <th class="px-3 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="order in jobOrders.data"
                        :key="order.id"
                        class="border-b last:border-0"
                        data-test="job-order-row"
                    >
                        <td class="px-3 py-3 font-medium text-brand-dark">
                            {{ order.company_name }}
                        </td>
                        <td class="px-3 py-3">{{ order.manpower }}</td>
                        <td class="px-3 py-3 whitespace-nowrap">
                            {{ order.male_count }} / {{ order.female_count }}
                        </td>
                        <td class="px-3 py-3 whitespace-nowrap">
                            {{ formatDate(order.interview_date) }}
                        </td>
                        <td class="px-3 py-3 whitespace-nowrap">
                            {{ formatDate(order.start_date) }}
                        </td>
                        <td class="px-3 py-3">
                            <span class="inline-flex items-center gap-1.5">
                                {{ order.workers_count }}
                                <TriangleAlert
                                    v-if="order.exceeds_manpower"
                                    class="size-4 text-warning-foreground"
                                    role="img"
                                    :aria-label="exceedsText(order)"
                                    :title="exceedsText(order)"
                                    data-test="exceeds-manpower-marker"
                                />
                            </span>
                        </td>
                        <td class="px-3 py-3">
                            <StatusBadge
                                kind="jobOrderStatus"
                                :status="order.status"
                            />
                        </td>
                        <td class="px-3 py-3 text-right whitespace-nowrap">
                            <JobOrderActions
                                :job-order="order"
                                layout="icons"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else class="py-8 text-center text-muted-foreground">
            {{ t('jobOrders.empty') }}
        </p>

        <Pagination :meta="jobOrders.meta" :links="jobOrders.links" />
    </section>
</template>
