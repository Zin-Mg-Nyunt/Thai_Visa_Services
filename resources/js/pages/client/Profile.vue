<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Cake, LogOut, Phone, UserRound } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { destroy } from '@/actions/App/Http/Controllers/Client/SessionController';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';
import { pageTitle } from '@/lib/pageTitle';
import type { ClientProfile } from '@/types';

const props = defineProps<{
    token: string;
    profile: { data: ClientProfile };
}>();

const { t } = useI18n();

const person = computed(() => props.profile.data);

const details = computed(() => [
    {
        label: t('clients.form.phone'),
        value: person.value.phone,
        icon: Phone,
    },
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
]);

const isSigningOut = ref(false);

const signOut = () => {
    router.post(
        destroy.url({ client: props.token }),
        {},
        {
            onStart: () => (isSigningOut.value = true),
            onFinish: () => (isSigningOut.value = false),
        },
    );
};
</script>

<template>
    <Head :title="pageTitle('clientArea.head')" />

    <section class="rounded-2xl border bg-card p-4 shadow-xs sm:p-6">
        <div class="flex items-center gap-4">
            <div
                class="flex size-16 shrink-0 items-center justify-center rounded-full bg-mint text-xl font-bold text-brand-dark"
                aria-hidden="true"
            >
                {{ person.name.charAt(0).toUpperCase() }}
            </div>
            <div class="min-w-0">
                <h1
                    class="text-xl font-bold wrap-break-word text-brand-dark"
                    data-test="client-welcome"
                >
                    {{ t('clientArea.welcome', { name: person.name }) }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ t('clientArea.intro') }}
                </p>
            </div>
        </div>

        <h2 class="mt-4 text-sm font-semibold text-brand-dark sm:mt-6">
            {{ t('clientArea.detailsTitle') }}
        </h2>
        <dl class="mt-2 divide-y" data-test="client-details">
            <div
                v-for="item in details"
                :key="item.label"
                class="flex items-start justify-between gap-3 py-2 text-sm sm:gap-4 sm:py-3"
            >
                <dt
                    class="flex shrink-0 items-center gap-2 text-muted-foreground"
                >
                    <component :is="item.icon" class="size-4 shrink-0" />
                    {{ item.label }}
                </dt>
                <dd
                    class="min-w-0 text-right font-medium wrap-break-word text-brand-dark"
                >
                    {{ item.value }}
                </dd>
            </div>
        </dl>

        <p class="mt-2 text-xs text-muted-foreground">
            {{ t('clientArea.contactAgency') }}
        </p>

        <Button
            type="button"
            variant="pillOutline"
            class="mt-4 sm:mt-6"
            :disabled="isSigningOut"
            data-test="client-logout"
            @click="signOut"
        >
            <LogOut class="size-4" />
            {{ t('clientArea.signOut') }}
        </Button>
    </section>
</template>
