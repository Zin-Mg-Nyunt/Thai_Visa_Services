<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Pencil, Plus, Trash } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { destroy } from '@/actions/App/Http/Controllers/Admin/ServiceController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import StatCard from '@/components/StatCard.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/format';
import { pageTitle } from '@/lib/pageTitle';
import { localised } from '@/lib/service';
import { index as clientsIndex } from '@/routes/admin/clients';
import { create, edit, index, show } from '@/routes/admin/services';
import type { Service, ServiceBreakdownRow, ServiceStats } from '@/types';

const props = defineProps<{
    service: { data: Service };
    subServices: { data: Service[] };
    canHaveSubServices: boolean;
    stats: ServiceStats;
    breakdown: ServiceBreakdownRow[];
    canDelete: boolean;
}>();

const { t } = useI18n();

const item = computed(() => props.service.data);

const titleOf = (service: { title: string | null; title_my: string | null }) =>
    localised(service.title, service.title_my) || t('services.untitled');

const statCards = computed(() => {
    const stats = props.stats;

    return [
        {
            label: t('services.stats.totalClients'),
            value: stats.total_clients,
        },
        {
            label: t('services.stats.inProgress'),
            value: stats.in_progress,
        },
        {
            label: t('services.stats.complete'),
            value: stats.complete,
        },
        {
            label: t('services.stats.cancelled'),
            value: stats.cancelled,
        },
        {
            label: t('services.stats.totalReceived'),
            value: formatMoney(stats.total_received),
        },
    ];
});

const isDeleteOpen = ref(false);
const isDeleting = ref(false);

const deleteService = () => {
    router.delete(destroy.url(item.value.id), {
        onStart: () => (isDeleting.value = true),
        onFinish: () => (isDeleting.value = false),
    });
};

const backHref = computed(() =>
    item.value.parent_id === null ? index() : show(item.value.parent_id),
);

const yesNo = (value: boolean) =>
    value ? t('services.details.yes') : t('services.details.no');

const details = computed(() => [
    {
        label: t('services.details.titleEn'),
        value: item.value.title || t('services.details.notSet'),
    },
    {
        label: t('services.details.titleMy'),
        value: item.value.title_my || t('services.details.notSet'),
    },
    {
        label: t('services.details.parent'),
        value: item.value.parent
            ? titleOf(item.value.parent)
            : t('services.details.none'),
    },
    {
        label: t('services.details.defaultFee'),
        value:
            item.value.default_fee === null
                ? t('services.details.notSet')
                : formatMoney(item.value.default_fee),
    },
    {
        label: t('services.details.hasDocuments'),
        value: yesNo(item.value.has_documents),
    },
    {
        label: t('services.details.showOnWebsite'),
        value: yesNo(item.value.show_on_website),
    },
]);
</script>

<template>
    <Head :title="titleOf(item)" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <h1
                class="text-2xl leading-relaxed font-bold wrap-break-word text-brand-dark"
            >
                {{ titleOf(item) }}
            </h1>
            <p v-if="item.parent" class="text-sm text-muted-foreground">
                <Link :href="show(item.parent.id)" class="hover:underline">
                    {{
                        t('services.subServiceOf', {
                            name: titleOf(item.parent),
                        })
                    }}
                </Link>
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2 sm:gap-3">
            <Button as-child variant="pillOutline">
                <Link
                    :href="backHref"
                    :aria-label="t('common.back')"
                    :title="t('common.back')"
                >
                    <ArrowLeft class="size-4" />
                    <span class="hidden sm:inline">{{ t('common.back') }}</span>
                </Link>
            </Button>
            <Button
                v-if="canDelete"
                variant="pillOutline"
                class="border-destructive text-destructive hover:bg-destructive hover:text-destructive-foreground"
                :aria-label="t('common.delete')"
                :title="t('common.delete')"
                data-test="delete-service-button"
                @click="isDeleteOpen = true"
            >
                <Trash class="size-4" />
                <span class="hidden sm:inline">{{ t('common.delete') }}</span>
            </Button>
            <Button as-child variant="pill">
                <Link
                    :href="edit(item.id)"
                    :aria-label="t('common.edit')"
                    :title="t('common.edit')"
                    data-test="edit-service-link"
                >
                    <Pencil class="size-4" />
                    <span class="hidden sm:inline">{{ t('common.edit') }}</span>
                </Link>
            </Button>
        </div>
    </div>

    <p
        v-if="item.is_job_search"
        class="mb-6 rounded-xl border bg-mint/40 p-4 text-sm text-brand-dark"
    >
        {{ t('services.jobSearchNote') }}
    </p>

    <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard
            v-for="stat in statCards"
            :key="stat.label"
            :label="stat.label"
            :value="stat.value"
        />
    </div>

    <div class="grid items-start gap-6 lg:grid-cols-2">
        <section class="rounded-2xl border bg-card p-4 shadow-xs sm:p-6">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-brand-dark">
                    {{ t('services.details.title') }}
                </h2>
                <StatusBadge kind="serviceAvailability" :status="item.status" />
            </div>

            <dl class="mt-4 divide-y">
                <div
                    v-for="row in details"
                    :key="row.label"
                    class="flex flex-col gap-1 py-3 text-sm sm:flex-row sm:items-start sm:justify-between sm:gap-4"
                >
                    <dt class="text-muted-foreground">{{ row.label }}</dt>
                    <dd
                        class="font-medium wrap-break-word text-brand-dark sm:text-right"
                    >
                        {{ row.value }}
                    </dd>
                </div>
            </dl>

            <div class="mt-2 space-y-3 border-t pt-4 text-sm">
                <p class="font-medium text-muted-foreground">
                    {{ t('services.details.description') }}
                </p>
                <div>
                    <p class="text-xs text-muted-foreground">
                        {{ t('services.details.descriptionEn') }}
                    </p>
                    <p class="wrap-break-word text-brand-dark">
                        {{
                            item.public_description ||
                            t('services.details.notSet')
                        }}
                    </p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">
                        {{ t('services.details.descriptionMy') }}
                    </p>
                    <p class="leading-relaxed wrap-break-word text-brand-dark">
                        {{
                            item.public_description_my ||
                            t('services.details.notSet')
                        }}
                    </p>
                </div>
            </div>
        </section>

        <section
            v-if="canHaveSubServices"
            class="rounded-2xl border bg-card p-4 shadow-xs sm:p-6"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-brand-dark">
                    {{ t('services.subServices') }}
                </h2>
                <Button as-child variant="pillOutline" size="sm">
                    <Link
                        :href="create({ query: { parent: item.id } })"
                        data-test="add-sub-service-link"
                    >
                        <Plus class="size-4" />
                        {{ t('services.addSubService') }}
                    </Link>
                </Button>
            </div>

            <ul v-if="subServices.data.length" class="mt-4 divide-y">
                <li
                    v-for="sub in subServices.data"
                    :key="sub.id"
                    class="flex items-center justify-between gap-3 py-3 text-sm"
                >
                    <div class="min-w-0">
                        <Link
                            :href="show(sub.id)"
                            class="font-medium wrap-break-word text-brand-dark hover:underline"
                            data-test="sub-service-link"
                        >
                            {{ titleOf(sub) }}
                        </Link>
                        <p class="text-xs text-muted-foreground">
                            {{
                                sub.default_fee === null
                                    ? t('services.noFee')
                                    : formatMoney(sub.default_fee)
                            }}
                        </p>
                    </div>
                    <StatusBadge
                        kind="serviceAvailability"
                        :status="sub.status"
                    />
                </li>
            </ul>
            <p v-else class="mt-4 text-sm text-muted-foreground">
                {{ t('services.noSubServices') }}
            </p>
        </section>

        <section
            v-if="canHaveSubServices && breakdown.length > 1"
            class="rounded-2xl border bg-card p-4 shadow-xs sm:p-6 lg:col-span-2"
            data-test="sub-service-breakdown"
        >
            <h2 class="text-lg font-semibold text-brand-dark">
                {{ t('services.breakdown.title') }}
            </h2>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('services.breakdown.hint') }}
            </p>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead
                        class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        <tr class="border-b">
                            <th class="px-3 py-3">
                                {{ t('services.breakdown.name') }}
                            </th>
                            <th class="px-3 py-3 text-right">
                                {{ t('services.stats.totalClients') }}
                            </th>
                            <th class="px-3 py-3 text-right">
                                {{ t('services.stats.inProgress') }}
                            </th>
                            <th class="px-3 py-3 text-right">
                                {{ t('services.stats.complete') }}
                            </th>
                            <th class="px-3 py-3 text-right">
                                {{ t('services.stats.cancelled') }}
                            </th>
                            <th class="px-3 py-3 text-right">
                                {{ t('services.stats.totalReceived') }}
                            </th>
                            <th class="px-3 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in breakdown"
                            :key="row.sub_service?.id ?? 'main-only'"
                            class="border-b last:border-0"
                            data-test="breakdown-row"
                        >
                            <td
                                class="px-3 py-3 font-medium wrap-break-word text-brand-dark"
                            >
                                {{
                                    row.sub_service
                                        ? titleOf(row.sub_service)
                                        : t('clientServices.mainOnly')
                                }}
                            </td>
                            <td class="px-3 py-3 text-right">
                                {{ row.stats.total_clients }}
                            </td>
                            <td class="px-3 py-3 text-right">
                                {{ row.stats.in_progress }}
                            </td>
                            <td class="px-3 py-3 text-right">
                                {{ row.stats.complete }}
                            </td>
                            <td class="px-3 py-3 text-right">
                                {{ row.stats.cancelled }}
                            </td>
                            <td class="px-3 py-3 text-right whitespace-nowrap">
                                {{ formatMoney(row.stats.total_received) }}
                            </td>
                            <td class="px-3 py-3 text-right">
                                <Link
                                    :href="
                                        clientsIndex({
                                            query: {
                                                service: item.id,
                                                sub_service:
                                                    row.sub_service?.id ??
                                                    'none',
                                            },
                                        })
                                    "
                                    class="text-sm whitespace-nowrap text-brand-dark underline-offset-2 hover:underline"
                                    data-test="breakdown-clients-link"
                                >
                                    {{ t('services.breakdown.viewClients') }}
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <ConfirmDialog
        v-model:open="isDeleteOpen"
        :title="t('services.delete.title')"
        :description="
            item.parent_id === null
                ? t('services.delete.description')
                : t('services.delete.subDescription')
        "
        :processing="isDeleting"
        @confirm="deleteService"
    />
</template>
