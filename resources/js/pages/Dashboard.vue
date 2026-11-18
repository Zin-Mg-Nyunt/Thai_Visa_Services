<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ChevronDown,
    CircleDollarSign,
    Hourglass,
    Plus,
    TrendingUp,
    Users,
} from '@lucide/vue';
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import StatCard from '@/components/StatCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dashboard } from '@/routes';
import { create } from '@/routes/admin/clients';
import { formatDate, formatMoney } from '@/lib/format';
import { localised } from '@/lib/service';
import type {
    BusinessReport,
    DashboardRange,
    DashboardRangeKey,
} from '@/types';

const props = defineProps<{
    range: DashboardRange;
    /** The custom dates as typed: kept when they are not valid, so they can be fixed. */
    customInput: { from: string; to: string };
    /** Errors of a custom range that is not valid; the figures then show this month. */
    rangeErrors: { from?: string; to?: string };
    report: BusinessReport;
}>();

const { t, locale } = useI18n();

const rangeKeys: DashboardRangeKey[] = [
    'this_month',
    'last_month',
    'this_year',
    'custom',
];
const rangeLabelKey = {
    this_month: 'thisMonth',
    last_month: 'lastMonth',
    this_year: 'thisYear',
    custom: 'custom',
} as const;

const chosen = ref<DashboardRangeKey>(props.range.key);
const custom = reactive({ ...props.customInput });

const visit = (query: Record<string, string>) =>
    router.get(dashboard.url(), query, {
        preserveScroll: true,
        replace: true,
    });

const chooseRange = (value: unknown) => {
    chosen.value = value as DashboardRangeKey;

    if (chosen.value !== 'custom') {
        visit({ range: chosen.value });
    }
};

const applyCustom = () =>
    visit({ range: 'custom', from: custom.from, to: custom.to });

const titleOf = (service: { title: string | null; title_my: string | null }) =>
    localised(service.title, service.title_my) || t('services.untitled');

const monthLabel = (value: string) => {
    const [year, month] = value.split('-').map(Number);
    const tag = locale.value === 'my' ? 'my-MM' : 'en-GB';
    const name = new Date(Date.UTC(year, month - 1, 1)).toLocaleDateString(
        tag,
        { month: 'short', timeZone: 'UTC' },
    );

    return month === 1 ? `${name} ${year}` : name;
};

// Money is a decimal string from the server, so a negative amount starts with a minus sign.
const isNegative = (amount: string) => amount.startsWith('-');

const expanded = ref<number[]>([]);
const toggle = (id: number) => {
    expanded.value = expanded.value.includes(id)
        ? expanded.value.filter((value) => value !== id)
        : [...expanded.value, id];
};
</script>

<template>
    <Head :title="t('dashboard.title')" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold text-brand-dark">
            {{ t('dashboard.title') }}
        </h1>
        <div class="flex flex-wrap items-center gap-2 sm:gap-3">
            <Select :model-value="chosen" @update:model-value="chooseRange">
                <SelectTrigger
                    class="w-44"
                    :aria-label="t('dashboard.range.label')"
                    data-test="dashboard-range"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="key in rangeKeys"
                        :key="key"
                        :value="key"
                    >
                        {{ t(`dashboard.range.${rangeLabelKey[key]}`) }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <Button as-child variant="pill">
                <Link
                    :href="create()"
                    :aria-label="t('clients.new')"
                    :title="t('clients.new')"
                >
                    <Plus class="size-4" />
                    <span class="hidden sm:inline">{{ t('clients.new') }}</span>
                </Link>
            </Button>
        </div>
    </div>

    <form
        v-if="chosen === 'custom'"
        class="mb-6 flex flex-wrap items-end gap-3 rounded-2xl border bg-card p-4"
        data-test="custom-range"
        @submit.prevent="applyCustom"
    >
        <div class="grid gap-1">
            <label class="text-xs text-muted-foreground" for="range-from">{{
                t('dashboard.range.from')
            }}</label>
            <Input
                id="range-from"
                v-model="custom.from"
                type="date"
                :aria-invalid="!!rangeErrors.from"
            />
            <InputError
                :message="rangeErrors.from"
                data-test="range-error-from"
            />
        </div>
        <div class="grid gap-1">
            <label class="text-xs text-muted-foreground" for="range-to">{{
                t('dashboard.range.to')
            }}</label>
            <Input
                id="range-to"
                v-model="custom.to"
                type="date"
                :min="custom.from || undefined"
                :aria-invalid="!!rangeErrors.to"
            />
            <InputError :message="rangeErrors.to" data-test="range-error-to" />
        </div>
        <Button type="submit" variant="pill" data-test="apply-range">
            {{ t('dashboard.range.apply') }}
        </Button>
    </form>

    <p class="mb-4 text-sm text-muted-foreground" data-test="range-dates">
        {{
            t('dashboard.showing', {
                from: formatDate(range.from),
                to: formatDate(range.to),
            })
        }}
    </p>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
            :label="t('dashboard.cards.received')"
            :value="formatMoney(report.received)"
            :icon="CircleDollarSign"
            data-test="card-received"
        >
            <p class="text-xs text-muted-foreground">
                {{
                    t('dashboard.cards.refunded', {
                        amount: formatMoney(report.refunded),
                    })
                }}
            </p>
        </StatCard>
        <StatCard
            :label="t('dashboard.cards.outstanding')"
            :value="formatMoney(report.outstanding)"
            :icon="Hourglass"
            data-test="card-outstanding"
        >
            <p class="text-xs text-muted-foreground">
                {{ t('dashboard.cards.outstandingHint') }}
            </p>
        </StatCard>
        <StatCard
            :label="t('dashboard.cards.unassigned')"
            :value="formatMoney(report.unassigned_kept)"
            :icon="Hourglass"
            data-test="card-unassigned"
        >
            <p class="text-xs text-muted-foreground">
                {{ t('dashboard.cards.unassignedHint') }}
            </p>
        </StatCard>
        <StatCard
            :label="t('dashboard.cards.profit')"
            :value="t('dashboard.cards.profitSoon')"
            :icon="TrendingUp"
            class="pointer-events-none opacity-60 select-none"
            aria-disabled="true"
            data-test="card-profit"
        >
            <p
                class="inline-block rounded-full bg-warning px-2 py-0.5 text-xs font-medium text-warning-foreground"
                data-test="coming-soon-badge"
            >
                {{ t('dashboard.cards.comingSoon') }}
            </p>
        </StatCard>
        <StatCard
            :label="t('dashboard.cards.activeClients')"
            :value="report.active_clients"
            :icon="Users"
            data-test="card-active-clients"
        >
            <p class="text-xs text-muted-foreground">
                {{ t('dashboard.cards.activeClientsHint') }}
            </p>
        </StatCard>
    </div>

    <div class="grid items-start gap-6 lg:grid-cols-2">
        <section class="rounded-2xl border bg-card p-4 shadow-xs sm:p-6">
            <h2 class="text-lg font-semibold text-brand-dark">
                {{ t('dashboard.byMonth.title') }}
            </h2>
            <p class="mt-1 text-xs text-muted-foreground">
                {{ t('dashboard.byMonth.hint') }}
            </p>

            <ul
                class="mt-6 flex h-48 items-end gap-1 sm:gap-2"
                data-test="month-bars"
            >
                <li
                    v-for="row in report.months"
                    :key="row.month"
                    class="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1"
                    :title="`${monthLabel(row.month)}: ${formatMoney(row.received)}`"
                >
                    <span class="sr-only"
                        >{{ monthLabel(row.month) }}:
                        {{ formatMoney(row.received) }}</span
                    >
                    <!-- A month below zero (refunds more than payments) gets a bar in the warning colour. -->
                    <div
                        class="w-full rounded-t-md"
                        :class="
                            isNegative(row.received)
                                ? 'border border-warning-foreground/40 bg-warning'
                                : 'bg-brand'
                        "
                        :style="{
                            height: `${Math.max(row.height_percent, row.height_percent > 0 ? 3 : 0)}%`,
                        }"
                        aria-hidden="true"
                        :data-test="
                            isNegative(row.received)
                                ? 'month-bar-negative'
                                : 'month-bar'
                        "
                    />
                    <span
                        class="w-full truncate text-center text-[10px] text-muted-foreground"
                        aria-hidden="true"
                        >{{ monthLabel(row.month) }}</span
                    >
                </li>
            </ul>
        </section>

        <section class="rounded-2xl border bg-card p-4 shadow-xs sm:p-6">
            <h2 class="text-lg font-semibold text-brand-dark">
                {{ t('dashboard.byService.title') }}
            </h2>

            <ul
                v-if="report.by_service.length"
                class="mt-4 divide-y"
                data-test="by-service"
            >
                <li
                    v-for="row in report.by_service"
                    :key="row.service.id"
                    class="py-3 text-sm"
                >
                    <div class="flex items-center justify-between gap-3">
                        <button
                            type="button"
                            class="flex min-w-0 items-center gap-1 text-left font-medium text-brand-dark"
                            :aria-expanded="expanded.includes(row.service.id)"
                            @click="toggle(row.service.id)"
                        >
                            <ChevronDown
                                class="size-4 shrink-0 transition-transform"
                                :class="{
                                    '-rotate-90': !expanded.includes(
                                        row.service.id,
                                    ),
                                }"
                            />
                            <span class="wrap-break-word">{{
                                titleOf(row.service)
                            }}</span>
                            <span
                                v-if="row.service.is_inactive"
                                class="shrink-0 text-xs font-normal text-muted-foreground"
                                >({{ t('serviceAvailability.inactive') }})</span
                            >
                        </button>
                        <span class="shrink-0 font-semibold">{{
                            formatMoney(row.received)
                        }}</span>
                    </div>
                    <div class="mt-2 h-1.5 rounded-full bg-muted">
                        <div
                            class="h-full rounded-full bg-brand"
                            :style="{ width: `${row.share_percent}%` }"
                        />
                    </div>
                    <ul
                        v-if="expanded.includes(row.service.id)"
                        class="mt-2 space-y-1 pl-5 text-muted-foreground"
                    >
                        <li
                            v-for="sub in row.sub_services"
                            :key="sub.sub_service?.id ?? 'main-only'"
                            class="flex items-center justify-between gap-3"
                        >
                            <span class="wrap-break-word">{{
                                sub.sub_service
                                    ? titleOf(sub.sub_service)
                                    : t('clientServices.mainOnly')
                            }}</span>
                            <span class="shrink-0">{{
                                formatMoney(sub.received)
                            }}</span>
                        </li>
                    </ul>
                </li>
            </ul>
            <p v-else class="mt-4 text-sm text-muted-foreground">
                {{ t('dashboard.byService.empty') }}
            </p>
        </section>
    </div>
</template>
