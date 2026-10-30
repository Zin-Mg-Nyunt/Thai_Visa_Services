<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import {
    store,
    update,
} from '@/actions/App/Http/Controllers/Admin/ClientController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index, show } from '@/routes/admin/clients';
import type { Client, Gender } from '@/types';

const props = defineProps<{
    client?: Client;
}>();

const { t } = useI18n();

const genders: Gender[] = ['male', 'female'];

const form = useForm({
    name: props.client?.name ?? '',
    phone: props.client?.phone ?? '',
    birthday: props.client?.birthday ?? '',
    gender: props.client?.gender ?? '',
    remarks: props.client?.remarks ?? '',
});

const cancelHref = () => (props.client ? show(props.client.id) : index());

const submit = () => {
    const target = props.client ? update(props.client.id) : store();

    form.submit(target.method, target.url);
};
</script>

<template>
    <form
        class="max-w-3xl space-y-6 rounded-2xl border bg-card p-6 shadow-xs"
        @submit.prevent="submit"
    >
        <div class="grid gap-6 md:grid-cols-2">
            <div class="grid gap-2">
                <Label for="name">{{ t('clients.form.name') }} *</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    name="name"
                    required
                    autocomplete="off"
                    :aria-invalid="!!form.errors.name"
                />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="phone">{{ t('clients.form.phone') }} *</Label>
                <Input
                    id="phone"
                    v-model="form.phone"
                    name="phone"
                    type="tel"
                    required
                    autocomplete="off"
                    placeholder="+66 81 234 5678"
                    :aria-invalid="!!form.errors.phone"
                />
                <p class="text-xs text-muted-foreground">
                    {{ t('clients.form.phoneHint') }}
                </p>
                <InputError :message="form.errors.phone" />
            </div>

            <div class="grid gap-2">
                <Label for="birthday">{{ t('clients.form.birthday') }} *</Label>
                <Input
                    id="birthday"
                    v-model="form.birthday"
                    name="birthday"
                    type="date"
                    required
                    :aria-invalid="!!form.errors.birthday"
                />
                <InputError :message="form.errors.birthday" />
            </div>

            <div class="grid gap-2">
                <Label for="gender">{{ t('clients.form.gender') }} *</Label>
                <Select v-model="form.gender">
                    <SelectTrigger
                        id="gender"
                        class="w-full"
                        :aria-invalid="!!form.errors.gender"
                    >
                        <SelectValue
                            :placeholder="t('clients.form.genderPlaceholder')"
                        />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="gender in genders"
                            :key="gender"
                            :value="gender"
                        >
                            {{ t(`clients.gender.${gender}`) }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.gender" />
            </div>

            <div class="grid gap-2 md:col-span-2">
                <Label for="remarks">{{ t('clients.form.remarks') }}</Label>
                <Input
                    id="remarks"
                    v-model="form.remarks"
                    name="remarks"
                    :placeholder="t('clients.form.remarksPlaceholder')"
                    :aria-invalid="!!form.errors.remarks"
                />
                <InputError :message="form.errors.remarks" />
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <Button as-child variant="pillOutline">
                <Link :href="cancelHref()">{{ t('common.cancel') }}</Link>
            </Button>
            <Button
                type="submit"
                variant="pill"
                :disabled="form.processing"
                data-test="save-client-button"
            >
                {{ t('common.save') }}
            </Button>
        </div>
    </form>
</template>
