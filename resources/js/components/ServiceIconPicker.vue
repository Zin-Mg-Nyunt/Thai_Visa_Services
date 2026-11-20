<script setup lang="ts">
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import {
    SERVICE_ICON_NAMES,
    SERVICE_ICONS,
    serviceIconName,
} from '@/lib/serviceIcons';
import type { ServiceIconName } from '@/lib/serviceIcons';
import { cn } from '@/lib/utils';

/**
 * Chooses the icon of a service's card on the landing page, from the allowed list only (lib/serviceIcons.ts, the same list the
 * server checks). The chosen icon is highlighted and previewed. A saved value of null, or one that is no longer allowed, is shown
 * as the fallback icon, which is also what the landing page shows for it, and stays as it is until the admin picks an icon.
 */
defineProps<{
    /** The id of the element that labels the group. */
    labelledby: string;
    /** The server's error for the icon, if any. */
    error?: string;
}>();

const model = defineModel<string | null>({ required: true });

const { t } = useI18n();

const selected = computed(() => serviceIconName(model.value));

/** The translated name of an icon; translation keys are the icon names in camelCase. */
const label = (name: ServiceIconName) =>
    t(
        `services.icons.${name.replace(/-(\w)/g, (_, letter: string) => letter.toUpperCase())}`,
    );
</script>

<template>
    <div class="grid gap-4">
        <div
            class="flex items-center gap-3 rounded-xl border bg-mint/30 p-3"
            data-test="service-icon-preview"
            :data-icon="selected"
        >
            <div
                class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-mint text-brand-dark"
            >
                <component :is="SERVICE_ICONS[selected]" class="size-6" />
            </div>
            <div class="min-w-0">
                <p class="text-xs text-muted-foreground">
                    {{ t('services.form.iconPreview') }}
                </p>
                <p class="text-sm font-medium wrap-break-word text-brand-dark">
                    {{ label(selected) }}
                </p>
            </div>
        </div>

        <div
            role="radiogroup"
            :aria-labelledby="labelledby"
            class="grid grid-cols-4 gap-2 min-[420px]:grid-cols-6 md:grid-cols-8"
        >
            <button
                v-for="name in SERVICE_ICON_NAMES"
                :key="name"
                type="button"
                role="radio"
                :aria-checked="name === selected"
                :aria-label="label(name)"
                :title="label(name)"
                :data-test="`service-icon-option-${name}`"
                :class="
                    cn(
                        'relative flex aspect-square items-center justify-center rounded-xl border bg-card text-brand-dark transition-colors outline-none hover:bg-mint/60 focus-visible:ring-[3px] focus-visible:ring-ring/50',
                        name === selected &&
                            'border-brand-dark bg-mint ring-2 ring-brand-dark',
                    )
                "
                @click="model = name"
            >
                <component :is="SERVICE_ICONS[name]" class="size-5" />
                <Check
                    v-if="name === selected"
                    class="absolute top-1 right-1 size-3"
                    aria-hidden="true"
                />
            </button>
        </div>

        <InputError :message="error" />
    </div>
</template>
