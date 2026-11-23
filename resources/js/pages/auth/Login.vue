<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useAuthLayoutProps } from '@/composables/useAuthLayoutProps';
import { redirect as googleRedirect } from '@/routes/auth/google';
import { store } from '@/routes/login';
import { pageTitle } from '@/lib/pageTitle';

defineProps<{
    status?: string;
    googleEnabled: boolean;
}>();

const { t } = useI18n();
const page = usePage();
useAuthLayoutProps('login');
</script>

<template>
    <Head :title="pageTitle('auth.login.head')" />

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        {{ status }}
    </div>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">{{ t('auth.email') }}</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    v-focus
                    :tabindex="1"
                    autocomplete="email"
                    placeholder="example@email.com"
                />
                <InputError
                    :message="errors.email || page.props.errors.email"
                />
            </div>

            <div class="grid gap-2">
                <Label for="password">{{ t('auth.password') }}</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    :tabindex="2"
                    autocomplete="current-password"
                    :placeholder="t('auth.password')"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center justify-between">
                <Label for="remember" class="flex items-center space-x-3">
                    <Checkbox id="remember" name="remember" :tabindex="3" />
                    <span>{{ t('auth.rememberMe') }}</span>
                </Label>
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                :tabindex="4"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                {{ t('auth.login.submit') }}
            </Button>
        </div>
    </Form>

    <div v-if="googleEnabled" class="mt-6 flex flex-col gap-4">
        <div
            class="flex items-center gap-3 text-xs text-muted-foreground uppercase"
        >
            <span class="h-px flex-1 bg-border" />
            {{ t('auth.login.or') }}
            <span class="h-px flex-1 bg-border" />
        </div>
        <Button
            variant="pillOutline"
            as="a"
            :href="googleRedirect().url"
            data-test="google-login-button"
            class="w-full"
        >
            {{ t('auth.login.google') }}
        </Button>
    </div>
</template>
