<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Pencil, TriangleAlert, UserCheck, UserX, Users } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import JobOrderActions from '@/components/JobOrderActions.vue';
import JobOrderWorkers from '@/components/JobOrderWorkers.vue';
import StatCard from '@/components/StatCard.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';
import { pageTitle } from '@/lib/pageTitle';
import { edit, index } from '@/routes/admin/job-search';
import type { JobOrder, JobOrderWorker } from '@/types';

defineProps<{
    jobOrder: { data: JobOrder };
    workers: { data: JobOrderWorker[] };
    workerFee: string | null;
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
                count: jobOrder.data.active_workers_count,
                manpower: jobOrder.data.manpower,
            })
        }}
    </p>

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <StatCard
            :label="t('jobOrders.show.requested')"
            :value="jobOrder.data.manpower"
            :icon="Users"
        >
            <p class="text-xs text-muted-foreground">
                {{ t('jobOrders.columns.male') }}
                {{ jobOrder.data.male_count }} ·
                {{ t('jobOrders.columns.female') }}
                {{ jobOrder.data.female_count }}
            </p>
        </StatCard>
        <StatCard
            :label="t('jobOrders.show.active')"
            :value="jobOrder.data.active_workers_count ?? 0"
            :icon="UserCheck"
            data-test="active-workers-card"
        />
        <StatCard
            :label="t('jobOrders.show.cancelledWorkers')"
            :value="jobOrder.data.cancelled_workers_count ?? 0"
            :icon="UserX"
            data-test="cancelled-workers-card"
        />
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
</template>
