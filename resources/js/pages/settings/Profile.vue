<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { pageTitle } from '@/lib/pageTitle';

const { t } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head :title="pageTitle('settings.profile.head')" />

    <h1 class="sr-only">{{ t('settings.profile.head') }}</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            :title="t('settings.profile.title')"
            :description="t('settings.profile.description')"
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">{{ t('auth.name') }}</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    :placeholder="t('auth.namePlaceholder')"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">{{ t('auth.email') }}</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    :placeholder="t('auth.email')"
                />
                <InputError class="mt-2" :message="errors.email" />
            </div>

            <div class="-mt-4 text-sm" data-test="email-verification-state">
                <p
                    v-if="user.email_verified_at"
                    class="font-medium text-green-600"
                    data-test="email-verified"
                >
                    {{ t('settings.profile.verified') }}
                </p>
                <div v-else class="space-y-1" data-test="email-unverified">
                    <p class="font-medium text-amber-600">
                        {{ t('settings.profile.unverified') }}
                    </p>
                    <p class="text-muted-foreground">
                        {{
                            page.props.googleEnabled
                                ? t('settings.profile.verifyWithGoogle')
                                : t('settings.profile.googleNotAvailable')
                        }}
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-4">
                <Button
                    :disabled="processing"
                    data-test="update-profile-button"
                    >{{ t('common.save') }}</Button
                >
            </div>
        </Form>
    </div>
</template>
