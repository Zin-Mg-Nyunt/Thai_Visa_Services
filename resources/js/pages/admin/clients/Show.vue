<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Cake,
    NotebookText,
    Pencil,
    Phone,
    Trash,
    UserRound,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { destroy } from '@/actions/App/Http/Controllers/Admin/ClientController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import TrackingAccess from '@/components/TrackingAccess.vue';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';
import { edit, index } from '@/routes/admin/clients';
import type { Client } from '@/types';

const props = defineProps<{
    client: { data: Client };
    trackingUrl: string;
}>();

const { t } = useI18n();

const person = computed(() => props.client.data);

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
            <TrackingAccess :client-name="person.name" :url="trackingUrl" />
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
            <div
                class="flex size-16 shrink-0 items-center justify-center rounded-full bg-mint text-xl font-bold text-brand-dark"
                aria-hidden="true"
            >
                {{ person.name.charAt(0).toUpperCase() }}
            </div>
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
