<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/format';
import { pageTitle } from '@/lib/pageTitle';
import { localised } from '@/lib/service';
import { create, show } from '@/routes/admin/services';
import type { Service, ServiceStats } from '@/types';

const props = defineProps<{
    services: { data: Service[] };
    stats: Record<number, ServiceStats>;
}>();

const { t } = useI18n();

const statRows = (service: Service) => {
    // A service nobody has taken has no entry yet, which means zero.
    const stats: ServiceStats = props.stats[service.id] ?? {
        total_clients: 0,
        in_progress: 0,
        complete: 0,
        total_received: '0.00',
    };

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
            label: t('services.stats.totalReceived'),
            value: formatMoney(stats.total_received),
        },
    ];
};
</script>

<template>
    <Head :title="pageTitle('services.title')" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold text-brand-dark">
            {{ t('services.title') }}
        </h1>
        <Button as-child variant="pill">
            <Link
                :href="create()"
                :aria-label="t('services.new')"
                :title="t('services.new')"
                data-test="new-service-link"
            >
                <Plus class="size-4" />
                <span class="hidden sm:inline">{{ t('services.new') }}</span>
            </Link>
        </Button>
    </div>

    <ul
        v-if="services.data.length"
        class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
    >
        <li v-for="service in services.data" :key="service.id">
            <Link
                :href="show(service.id)"
                class="block h-full rounded-2xl border bg-card p-5 shadow-xs transition-shadow hover:shadow-md"
                data-test="service-card"
            >
                <div class="flex items-start justify-between gap-3">
                    <h2
                        class="min-w-0 text-lg leading-relaxed font-semibold wrap-break-word text-brand-dark"
                    >
                        {{
                            localised(service.title, service.title_my) ||
                            t('services.untitled')
                        }}
                    </h2>
                    <StatusBadge
                        kind="serviceAvailability"
                        :status="service.status"
                    />
                </div>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{
                        service.default_fee === null
                            ? t('services.noFee')
                            : `${t('services.defaultFee')} ${formatMoney(service.default_fee)}`
                    }}
                </p>
                <dl class="mt-4 divide-y border-t text-sm">
                    <div
                        v-for="stat in statRows(service)"
                        :key="stat.label"
                        class="flex items-center justify-between gap-3 py-2"
                    >
                        <dt class="text-muted-foreground">{{ stat.label }}</dt>
                        <dd class="font-semibold text-brand-dark">
                            {{ stat.value }}
                        </dd>
                    </div>
                </dl>
                <p class="border-t pt-3 text-sm text-muted-foreground">
                    {{
                        t('services.subServicesCount', {
                            count: service.sub_services_count ?? 0,
                        })
                    }}
                </p>
            </Link>
        </li>
    </ul>
    <p
        v-else
        class="rounded-2xl border bg-card py-8 text-center text-muted-foreground"
    >
        {{ t('services.empty') }}
    </p>
</template>
