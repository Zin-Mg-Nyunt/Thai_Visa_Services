<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Banknote,
    ClipboardList,
    Pencil,
    TriangleAlert,
    UserCheck,
    Users,
} from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import CancelledApplications from '@/components/CancelledApplications.vue';
import JobOrderActions from '@/components/JobOrderActions.vue';
import JobOrderWorkers from '@/components/JobOrderWorkers.vue';
import StatCard from '@/components/StatCard.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { formatDate, formatMoney } from '@/lib/format';
import { pageTitle } from '@/lib/pageTitle';
import { edit, index } from '@/routes/admin/job-search';
import type { JobOrder, JobOrderMoney, JobOrderWorker } from '@/types';

defineProps<{
    jobOrder: { data: JobOrder };
    workers: { data: JobOrderWorker[] };
    cancelledWorkers: { data: JobOrderWorker[] };
    workerFee: string | null;
    money: JobOrderMoney;
}>();

const { t } = useI18n();
</script>

<template>
    <Head :title="pageTitle('jobOrders.show.head')" />

    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 space-y-1">
            <Link
                :href="index()"
                class="text-sm text-muted-foreground hover:underline"
            >
                {{ t('jobOrders.show.back') }}
            </Link>
            <h1
                class="flex flex-wrap items-center gap-3 text-2xl font-bold wrap-break-word text-brand-dark"
            >
                {{ jobOrder.data.company_name }}
                <StatusBadge
                    kind="jobOrderStatus"
                    :status="jobOrder.data.status"
                />
            </h1>
        </div>
        <div v-if="jobOrder.data.can_edit" class="flex flex-wrap gap-2">
            <Button as-child variant="pillOutline">
                <Link
                    :href="edit(jobOrder.data.id)"
                    data-test="edit-job-order-link"
                >
                    <Pencil class="size-4" />
                    {{ t('common.edit') }}
                </Link>
            </Button>
            <JobOrderActions :job-order="jobOrder.data" layout="labelled" />
        </div>
    </div>

    <p
        v-if="jobOrder.data.exceeds_manpower"
        class="mb-4 flex items-start gap-2 rounded-xl bg-warning px-3 py-2 text-sm text-warning-foreground"
        role="status"
        data-test="exceeds-manpower-warning"
    >
        <TriangleAlert class="mt-0.5 size-4 shrink-0" />
        {{
            t('jobOrders.exceeds', {
                count: jobOrder.data.hired_workers_count,
                manpower: jobOrder.data.manpower,
            })
        }}
    </p>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
            :label="t('jobOrders.show.requested')"
            :value="jobOrder.data.manpower"
            :icon="Users"
            data-test="manpower-card"
        >
            <p class="text-xs text-muted-foreground">
                {{ t('jobOrders.columns.male') }}
                {{ jobOrder.data.male_count }} ·
                {{ t('jobOrders.columns.female') }}
                {{ jobOrder.data.female_count }}
            </p>
        </StatCard>
        <StatCard
            :label="t('jobOrders.show.applied')"
            :value="jobOrder.data.applied_workers_count ?? 0"
            :icon="ClipboardList"
            data-test="applied-workers-card"
        >
            <p class="text-xs text-muted-foreground">
                {{ t('jobOrders.show.appliedHint') }}
            </p>
        </StatCard>
        <StatCard
            :label="t('jobOrders.show.hired')"
            :value="jobOrder.data.hired_workers_count ?? 0"
            :icon="UserCheck"
            data-test="hired-workers-card"
        >
            <p class="text-xs text-muted-foreground">
                {{ t('jobOrders.show.hiredHint') }}
            </p>
        </StatCard>
        <StatCard
            :label="t('jobOrders.show.money')"
            :value="formatMoney(money.amount)"
            :icon="Banknote"
            data-test="money-card"
        >
            <p class="text-xs text-muted-foreground">
                {{ t('jobOrders.show.moneyHint') }}
            </p>
            <p
                v-if="money.includes_deleted"
                class="text-xs text-muted-foreground"
                data-test="money-includes-deleted"
            >
                {{ t('jobOrders.show.includesDeleted') }}
            </p>
        </StatCard>
    </div>

    <section
        class="max-w-3xl space-y-3 rounded-2xl border bg-card p-4 text-sm shadow-xs sm:p-6"
        data-test="job-order-details"
    >
        <dl class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2">
            <dt class="text-muted-foreground">
                {{ t('jobOrders.columns.interview') }}
            </dt>
            <dd>{{ formatDate(jobOrder.data.interview_date) || '–' }}</dd>
            <dt class="text-muted-foreground">
                {{ t('jobOrders.columns.start') }}
            </dt>
            <dd>{{ formatDate(jobOrder.data.start_date) || '–' }}</dd>
            <dt class="text-muted-foreground">
                {{ t('jobOrders.form.notes') }}
            </dt>
            <dd class="wrap-break-word whitespace-pre-line">
                {{ jobOrder.data.notes || '–' }}
            </dd>
        </dl>
    </section>

    <JobOrderWorkers
        :job-order="jobOrder.data"
        :workers="workers.data"
        :default-fee="workerFee"
    />

    <CancelledApplications :workers="cancelledWorkers.data" />
</template>
