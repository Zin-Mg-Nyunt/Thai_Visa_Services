<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Cake,
    NotebookText,
    Pencil,
    Phone,
    Plus,
    Trash,
    UserRound,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { destroy } from '@/actions/App/Http/Controllers/Admin/ClientController';
import ClientAvatar from '@/components/ClientAvatar.vue';
import ClientPhotoActions from '@/components/ClientPhotoActions.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import PinAttemptLog from '@/components/PinAttemptLog.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import TrackingAccess from '@/components/TrackingAccess.vue';
import { Button } from '@/components/ui/button';
import { formatDate, formatMoney } from '@/lib/format';
import { localised } from '@/lib/service';
import { serviceStatusKey } from '@/lib/status';
import { edit, index } from '@/routes/admin/clients';
import {
    create as addService,
    edit as editService,
} from '@/routes/admin/clients/services';
import type { Client, ClientService, PinAttempt } from '@/types';

const props = defineProps<{
    client: { data: Client };
    trackingUrl: string;
    photoUrl: string | null;
    clientServices: { data: ClientService[] };
    lockedMinutes: number;
    pinAttempts: { data: PinAttempt[] };
}>();

const { t } = useI18n();

const person = computed(() => props.client.data);

const titleOf = (service: { title: string | null; title_my: string | null }) =>
    localised(service.title, service.title_my) || t('services.untitled');

const dateOrDash = (value: string | null) =>
    value ? formatDate(value) : t('clientServices.notAvailable');

const dateRange = (service: ClientService) =>
    `${dateOrDash(service.start_date)} → ${dateOrDash(service.end_date)}`;

const serviceRows = (service: ClientService) => [
    { label: t('clientServices.fee'), value: formatMoney(service.fee) },
    {
        label: t('clientServices.received'),
        value: t('clientServices.notAvailable'),
    },
    {
        label: t('clientServices.remaining'),
        value: t('clientServices.notAvailable'),
    },
    {
        label: t('clientServices.payment'),
        value: t('clientServices.notAvailable'),
    },
];

const isDeleteOpen = ref(false);
const isDeleting = ref(false);

const deleteError = ref('');

watch(isDeleteOpen, () => {
    deleteError.value = '';
});

const deleteClient = (confirmation: string) => {
    router.delete(destroy.url(person.value.id), {
        data: { confirmation },
        onStart: () => {
            isDeleting.value = true;
            deleteError.value = '';
        },
        onError: (errors) => (deleteError.value = errors.confirmation ?? ''),
        onFinish: () => (isDeleting.value = false),
    });
};

const details = computed(() => [
    {
        label: t('clients.form.birthday'),
        value: formatDate(person.value.birthday),
        icon: Cake,
    },
    {
        label: t('clients.form.gender'),
        value: t(`clients.gender.${person.value.gender}`),
        icon: UserRound,
    },
    {
        label: t('clients.form.remarks'),
        value: person.value.remarks || t('clients.show.noRemarks'),
        icon: NotebookText,
    },
]);
</script>

<template>
    <Head :title="person.name" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="min-w-0 text-2xl font-bold wrap-break-word text-brand-dark">
            {{ person.name }}
        </h1>
        <div class="flex flex-wrap items-center gap-2 sm:gap-3">
            <Button as-child variant="pillOutline">
                <Link
                    :href="index()"
                    :aria-label="t('common.back')"
                    :title="t('common.back')"
                >
                    <ArrowLeft class="size-4" />
                    <span class="hidden sm:inline">{{ t('common.back') }}</span>
                </Link>
            </Button>
            <TrackingAccess
                :client-name="person.name"
                :url="trackingUrl"
                :photo-url="photoUrl"
            />
            <Button
                variant="pillOutline"
                class="border-destructive text-destructive hover:bg-destructive hover:text-destructive-foreground"
                :aria-label="t('common.delete')"
                :title="t('common.delete')"
                data-test="delete-client-button"
                @click="isDeleteOpen = true"
            >
                <Trash class="size-4" />
                <span class="hidden sm:inline">{{ t('common.delete') }}</span>
            </Button>
            <Button as-child variant="pill">
                <Link
                    :href="edit(person.id)"
                    :aria-label="t('common.edit')"
                    :title="t('common.edit')"
                    data-test="edit-client-link"
                >
                    <Pencil class="size-4" />
                    <span class="hidden sm:inline">{{ t('common.edit') }}</span>
                </Link>
            </Button>
        </div>
    </div>

    <section class="max-w-xl rounded-2xl border bg-card p-4 shadow-xs sm:p-6">
        <div class="flex items-center gap-4">
            <ClientAvatar
                :name="person.name"
                :photo-url="photoUrl"
                class="size-16 text-xl"
            />
            <div class="min-w-0">
                <p class="truncate text-lg font-semibold text-brand-dark">
                    {{ person.name }}
                </p>
                <p
                    class="flex items-center gap-1.5 text-sm break-all text-muted-foreground"
                >
                    <Phone class="size-3.5 shrink-0" />
                    {{ person.phone }}
                </p>
            </div>
        </div>

        <ClientPhotoActions
            class="mt-4"
            :client-id="person.id"
            :has-photo="photoUrl !== null"
        />

        <dl class="mt-6 divide-y">
            <div
                v-for="item in details"
                :key="item.label"
                class="flex flex-col gap-1 py-3 text-sm sm:flex-row sm:items-start sm:justify-between sm:gap-4"
            >
                <dt class="flex items-center gap-2 text-muted-foreground">
                    <component :is="item.icon" class="size-4 shrink-0" />
                    {{ item.label }}
                </dt>
                <dd
                    class="font-medium wrap-break-word text-brand-dark sm:text-right"
                >
                    {{ item.value }}
                </dd>
            </div>
        </dl>
    </section>

    <section class="mt-6">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-brand-dark">
                {{ t('clientServices.title') }}
            </h2>
            <Button as-child variant="pill" size="sm">
                <Link
                    :href="addService(person.id)"
                    data-test="add-client-service-link"
                >
                    <Plus class="size-4" />
                    {{ t('clientServices.add') }}
                </Link>
            </Button>
        </div>

        <ul
            v-if="clientServices.data.length"
            class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
        >
            <li
                v-for="service in clientServices.data"
                :key="service.id"
                class="rounded-2xl border bg-card p-5 shadow-xs"
                data-test="client-service-card"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3
                            class="text-lg leading-relaxed font-semibold wrap-break-word text-brand-dark"
                        >
                            {{
                                service.service ? titleOf(service.service) : ''
                            }}
                        </h3>
                        <p
                            class="text-sm leading-relaxed wrap-break-word text-muted-foreground"
                        >
                            {{
                                service.sub_service
                                    ? titleOf(service.sub_service)
                                    : t('clientServices.mainOnly')
                            }}
                        </p>
                    </div>
                    <StatusBadge
                        kind="serviceStatus"
                        :status="serviceStatusKey(service.status)"
                    />
                </div>
                <p class="mt-2 text-xs text-muted-foreground">
                    {{ dateRange(service) }}
                </p>
                <dl class="mt-3 divide-y border-t text-sm">
                    <div
                        v-for="row in serviceRows(service)"
                        :key="row.label"
                        class="flex items-center justify-between gap-3 py-2"
                    >
                        <dt class="text-muted-foreground">{{ row.label }}</dt>
                        <dd class="font-semibold text-brand-dark">
                            {{ row.value }}
                        </dd>
                    </div>
                </dl>
                <Button
                    as-child
                    variant="pillOutline"
                    size="sm"
                    class="mt-3 w-full"
                >
                    <Link
                        :href="
                            editService({
                                client: person.id,
                                clientService: service.id,
                            })
                        "
                        data-test="edit-client-service-link"
                    >
                        <Pencil class="size-4" />
                        {{ t('common.edit') }}
                    </Link>
                </Button>
            </li>
        </ul>
        <p
            v-else
            class="rounded-2xl border bg-card py-8 text-center text-muted-foreground"
        >
            {{ t('clientServices.empty') }}
        </p>
    </section>

    <PinAttemptLog
        :client-id="person.id"
        :attempts="pinAttempts.data"
        :locked-minutes="lockedMinutes"
    />

    <ConfirmDialog
        v-model:open="isDeleteOpen"
        :title="t('clients.delete.title')"
        :description="t('clients.delete.description')"
        :required-text="person.phone"
        :required-text-label="
            t('clients.delete.confirmLabel', { phone: person.phone })
        "
        :error="deleteError"
        :processing="isDeleting"
        @confirm="deleteClient"
    />
</template>
