<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { LogOut } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { destroy } from '@/actions/App/Http/Controllers/Client/SessionController';
import { Button } from '@/components/ui/button';
import { pageTitle } from '@/lib/pageTitle';

const props = defineProps<{
    token: string;
    clientName: string;
}>();

const { t } = useI18n();

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
        <h1 class="text-xl font-bold wrap-break-word text-brand-dark">
            {{ t('clientArea.welcome', { name: clientName }) }}
        </h1>
        <p class="mt-2 text-sm text-muted-foreground">
            {{ t('clientArea.intro') }}
        </p>
        <Button
            type="button"
            variant="pillOutline"
            class="mt-6"
            :disabled="isSigningOut"
            data-test="client-logout"
            @click="signOut"
        >
            <LogOut class="size-4" />
            {{ t('clientArea.signOut') }}
        </Button>
    </section>
</template>
