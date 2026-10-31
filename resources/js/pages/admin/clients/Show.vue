<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { destroy } from '@/actions/App/Http/Controllers/Admin/ClientController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';
import { edit, index } from '@/routes/admin/clients';
import type { Client } from '@/types';

const props = defineProps<{
    client: { data: Client };
}>();

const { t } = useI18n();

const person = computed(() => props.client.data);

const isDeleteOpen = ref(false);
const isDeleting = ref(false);

const deleteError = ref('');

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
    },
    {
        label: t('clients.form.gender'),
        value: t(`clients.gender.${person.value.gender}`),
    },
    {
        label: t('clients.form.remarks'),
        value: person.value.remarks || t('clients.show.noRemarks'),
    },
]);
</script>

<template>
    <Head :title="person.name" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold text-brand-dark">{{ person.name }}</h1>
        <div class="flex items-center gap-3">
            <Button as-child variant="pillOutline">
                <Link :href="index()">{{ t('common.back') }}</Link>
            </Button>
            <Button
                variant="pillOutline"
                class="border-destructive text-destructive hover:bg-destructive hover:text-white"
                data-test="delete-client-button"
                @click="isDeleteOpen = true"
            >
                {{ t('common.delete') }}
            </Button>
            <Button as-child variant="pill">
                <Link :href="edit(person.id)" data-test="edit-client-link">{{
                    t('common.edit')
                }}</Link>
            </Button>
        </div>
    </div>

    <section class="max-w-xl rounded-2xl border bg-card p-6 shadow-xs">
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
                <p class="text-sm text-muted-foreground">{{ person.phone }}</p>
            </div>
        </div>

        <dl class="mt-6 divide-y">
            <div
                v-for="item in details"
                :key="item.label"
                class="flex items-start justify-between gap-4 py-3 text-sm"
            >
                <dt class="text-muted-foreground">{{ item.label }}</dt>
                <dd class="text-right font-medium text-brand-dark">
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
