<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { verify } from '@/actions/App/Http/Controllers/Client/TrackingController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { pageTitle } from '@/lib/pageTitle';

const props = defineProps<{
    token: string;
    lockedMinutes: number;
}>();

const { t } = useI18n();

const PIN_LENGTH = 4;

const digits = ref<string[]>(Array.from({ length: PIN_LENGTH }, () => ''));
const inputs = ref<HTMLInputElement[]>([]);

const form = useForm({ pin: '' });

const pin = computed(() => digits.value.join(''));
const isComplete = computed(() => pin.value.length === PIN_LENGTH);
const isLocked = computed(() => props.lockedMinutes > 0);
const message = computed(() =>
    isLocked.value
        ? t('track.locked', { minutes: props.lockedMinutes })
        : form.errors.pin,
);

let unlockTimer: ReturnType<typeof setTimeout> | undefined;

watch(
    () => props.lockedMinutes,
    (minutes) => {
        clearTimeout(unlockTimer);

        if (minutes > 0) {
            unlockTimer = setTimeout(
                () => router.reload({ only: ['lockedMinutes'] }),
                minutes * 60_000,
            );
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => clearTimeout(unlockTimer));

const focusInput = (index: number) => {
    inputs.value[index]?.focus();
    inputs.value[index]?.select();
};

const fillFrom = (index: number, value: string) => {
    const typed = value.replace(/\D/g, '').slice(0, PIN_LENGTH - index);

    typed.split('').forEach((digit, offset) => {
        digits.value[index + offset] = digit;
    });

    focusInput(Math.min(index + typed.length, PIN_LENGTH - 1));
};

const onInput = (index: number, event: Event) => {
    const input = event.target as HTMLInputElement;
    const value = input.value.replace(/\D/g, '');

    digits.value[index] = '';
    input.value = '';

    if (value !== '') {
        fillFrom(index, value);
    }
};

const onKeydown = (index: number, event: KeyboardEvent) => {
    if (event.key === 'Backspace' && digits.value[index] === '' && index > 0) {
        digits.value[index - 1] = '';
        focusInput(index - 1);
        event.preventDefault();
    }
};

const onPaste = (index: number, event: ClipboardEvent) => {
    event.preventDefault();
    fillFrom(index, event.clipboardData?.getData('text') ?? '');
};

const submit = () => {
    if (isLocked.value) {
        return;
    }

    form.pin = pin.value;
    form.post(verify.url({ client: props.token }), {
        onError: async () => {
            digits.value = Array.from({ length: PIN_LENGTH }, () => '');
            await nextTick();
            focusInput(0);
        },
    });
};
</script>

<template>
    <Head :title="pageTitle('track.head')" />

    <div class="space-y-2 text-center">
        <h1 class="text-3xl font-bold text-white">{{ t('track.title') }}</h1>
        <p class="text-sm text-white/80">{{ t('track.description') }}</p>
    </div>

    <form class="flex w-full flex-col gap-4" @submit.prevent="submit">
        <div
            class="flex justify-center gap-2 sm:gap-3"
            role="group"
            :aria-label="t('track.pinLabel')"
        >
            <input
                v-for="(_, index) in digits"
                :key="index"
                :ref="
                    (element) => (inputs[index] = element as HTMLInputElement)
                "
                :value="digits[index]"
                type="text"
                inputmode="numeric"
                pattern="[0-9]*"
                maxlength="4"
                :autocomplete="index === 0 ? 'one-time-code' : 'off'"
                :autofocus="index === 0"
                :aria-label="t('track.digitLabel', { number: index + 1 })"
                :disabled="isLocked"
                :aria-invalid="!!message"
                class="size-14 min-w-0 rounded-xl bg-white text-center text-2xl font-bold text-brand-dark outline-none focus-visible:ring-4 focus-visible:ring-brand aria-invalid:ring-4 aria-invalid:ring-destructive sm:size-16"
                data-test="pin-input"
                @input="onInput(index, $event)"
                @keydown="onKeydown(index, $event)"
                @paste="onPaste(index, $event)"
                @focus="($event.target as HTMLInputElement).select()"
            />
        </div>

        <InputError
            :message="message"
            class="text-center [&_p]:text-red-300"
            role="alert"
            data-test="pin-message"
        />

        <Button
            type="submit"
            class="w-full rounded-full font-semibold"
            :disabled="isLocked || !isComplete || form.processing"
            data-test="pin-submit"
        >
            <Spinner v-if="form.processing" />
            {{ t('track.submit') }}
        </Button>
    </form>
</template>
