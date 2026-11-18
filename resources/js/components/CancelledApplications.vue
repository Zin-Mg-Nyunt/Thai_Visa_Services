<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { show as showClient } from '@/routes/admin/clients';
import type { JobOrderWorker } from '@/types';

/**
 * The historical Cancelled applications of an order, apart from the workers: they stay as history and are never counted as
 * workers. Each application shows its current status only (there is no status history in V1). The person's name opens their
 * Client Detail page.
 */
defineProps<{ workers: JobOrderWorker[] }>();

const { t } = useI18n();
</script>

<template>
    <section
        v-if="workers.length"
        class="mt-6 max-w-3xl space-y-3 rounded-2xl border bg-card p-4 shadow-xs sm:p-6"
        data-test="cancelled-applications"
    >
        <div>
            <h2 class="font-medium text-brand-dark">
                {{
                    `${t('jobOrders.show.cancelledHistory')} (${workers.length})`
                }}
            </h2>
            <p class="text-xs text-muted-foreground">
                {{ t('jobOrders.show.cancelledHistoryHint') }}
            </p>
        </div>

        <ul class="divide-y">
            <li
                v-for="worker in workers"
                :key="worker.id"
                class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm opacity-80"
                data-test="cancelled-application-row"
            >
                <div class="min-w-0">
                    <Link
                        :href="showClient(worker.client_id)"
                        class="font-medium wrap-break-word text-brand-dark hover:underline"
                        data-test="worker-name-link"
                    >
                        {{ worker.name }}
                    </Link>
                    <p class="text-xs text-muted-foreground">
                        {{ worker.phone }}
                    </p>
                </div>
                <span
                    class="rounded-full bg-destructive/10 px-2.5 py-0.5 text-xs font-medium text-destructive"
                    data-test="cancelled-application-badge"
                >
                    {{ t('jobOrders.workers.cancelled') }}
                </span>
            </li>
        </ul>
    </section>
</template>
