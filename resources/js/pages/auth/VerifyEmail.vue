<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useAuthLayoutProps } from '@/composables/useAuthLayoutProps';
import { logout } from '@/routes';
import { send } from '@/routes/verification';
import { pageTitle } from '@/lib/pageTitle';

defineProps<{
    status?: string;
}>();

const { t } = useI18n();
useAuthLayoutProps('verify');
</script>

<template>
    <Head :title="pageTitle('auth.verify.head')" />

    <div
        v-if="status === 'verification-link-sent'"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        {{ t('auth.verify.sent') }}
    </div>

    <Form
        v-bind="send.form()"
        class="space-y-6 text-center"
        v-slot="{ processing }"
    >
        <Button :disabled="processing" variant="secondary">
            <Spinner v-if="processing" />
            {{ t('auth.verify.resend') }}
        </Button>

        <TextLink :href="logout()" as="button" class="mx-auto block text-sm">
            {{ t('auth.verify.logOut') }}
        </TextLink>
    </Form>
</template>
