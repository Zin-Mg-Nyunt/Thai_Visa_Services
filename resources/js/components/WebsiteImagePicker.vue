<script setup lang="ts">
import { ImagePlus, Trash, Undo2 } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { formatFileSize } from '@/lib/format';
import {
    ImageTooLargeError,
    MAX_IMAGE_BYTES,
    resizeImage,
    UnsupportedImageError,
    WEBSITE_IMAGE_PRESETS,
} from '@/lib/resizeImage';

/**
 * Chooses one website image (logo, favicon or hero image) to send with a form. The logo and the hero image are checked and made
 * smaller on the device (resizeImage); the favicon is only checked and is never changed. A preview and the final file size
 * show before saving. The server checks the type and the size again and decides.
 */
const props = defineProps<{
    kind: 'logo' | 'favicon' | 'hero';
    label: string;
    hint: string;
    /** The public address of the image that is saved now, or null when there is none. */
    currentUrl: string | null;
    /** Whether the saved image can be removed (only the hero image). */
    removable?: boolean;
    /** The server's error for this image, if any. */
    error?: string;
}>();

const file = defineModel<File | null>({ required: true });
const removed = defineModel<boolean>('removed', { default: false });

const { t } = useI18n();

const input = ref<HTMLInputElement | null>(null);
const localError = ref('');
const isPreparing = ref(false);
const previewUrl = ref<string | null>(null);

const isFavicon = computed(() => props.kind === 'favicon');
const accept = computed(() =>
    isFavicon.value
        ? 'image/png,image/x-icon,image/vnd.microsoft.icon,.ico'
        : 'image/png,image/jpeg,image/webp',
);
const error = computed(() => localError.value || props.error || '');
const shownUrl = computed(() =>
    removed.value ? null : (previewUrl.value ?? props.currentUrl),
);

// One preview link at a time; the old one is released so the browser can free the image.
watch(
    file,
    (chosen) => {
        if (previewUrl.value) {
            URL.revokeObjectURL(previewUrl.value);
        }

        previewUrl.value = chosen ? URL.createObjectURL(chosen) : null;
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
    }
});

// A browser often gives no type for an .ico file, so the name is also looked at; the server decides from the content.
const isAllowedFavicon = (chosen: File) =>
    ['image/png', 'image/x-icon', 'image/vnd.microsoft.icon'].includes(
        chosen.type,
    ) || /\.(ico|png)$/i.test(chosen.name);

const choose = async (event: Event) => {
    const target = event.target as HTMLInputElement;
    const chosen = target.files?.[0];

    target.value = '';
    localError.value = '';

    if (!chosen) {
        return;
    }

    if (isFavicon.value) {
        if (!isAllowedFavicon(chosen)) {
            localError.value = t('appearance.errors.faviconType');
        } else if (chosen.size > MAX_IMAGE_BYTES) {
            localError.value = t('appearance.errors.faviconTooLarge');
        } else {
            file.value = chosen;
            removed.value = false;
        }

        return;
    }

    isPreparing.value = true;

    try {
        file.value = await resizeImage(
            chosen,
            WEBSITE_IMAGE_PRESETS[props.kind === 'logo' ? 'logo' : 'hero'],
        );
        removed.value = false;
    } catch (exception) {
        localError.value =
            exception instanceof UnsupportedImageError
                ? t('appearance.errors.imageType')
                : exception instanceof ImageTooLargeError
                  ? t('appearance.errors.tooLarge')
                  : t('appearance.errors.unreadable');
    } finally {
        isPreparing.value = false;
    }
};

const undoChoice = () => {
    file.value = null;
    localError.value = '';
};
</script>

<template>
    <div class="grid gap-3" :data-test="`image-${kind}`">
        <div>
            <h3 class="text-sm font-semibold text-brand-dark">{{ label }}</h3>
            <p class="mt-1 text-xs text-muted-foreground">{{ hint }}</p>
        </div>

        <div class="flex flex-wrap items-center gap-4">
            <div
                class="flex h-24 w-40 shrink-0 items-center justify-center overflow-hidden rounded-xl border bg-mint/40"
                :class="{ 'w-24': isFavicon }"
            >
                <img
                    v-if="shownUrl"
                    :src="shownUrl"
                    alt=""
                    class="max-h-full max-w-full object-contain"
                    data-test="image-preview"
                />
                <span
                    v-else
                    class="px-2 text-center text-xs text-muted-foreground"
                    data-test="image-empty"
                >
                    {{
                        removed
                            ? t('appearance.willRemove')
                            : t('appearance.noImage')
                    }}
                </span>
            </div>

            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <input
                        ref="input"
                        type="file"
                        :accept="accept"
                        class="sr-only"
                        tabindex="-1"
                        data-test="image-input"
                        @change="choose"
                    />
                    <Button
                        type="button"
                        variant="pillOutline"
                        :disabled="isPreparing"
                        data-test="image-choose"
                        @click="input?.click()"
                    >
                        <ImagePlus class="size-4" />
                        {{
                            isPreparing
                                ? t('appearance.preparing')
                                : file || currentUrl
                                  ? t('appearance.change')
                                  : t('appearance.choose')
                        }}
                    </Button>
                    <Button
                        v-if="file"
                        type="button"
                        variant="pillOutline"
                        data-test="image-undo"
                        @click="undoChoice"
                    >
                        <Undo2 class="size-4" />
                        {{ t('appearance.undo') }}
                    </Button>
                    <Button
                        v-if="removable && currentUrl && !file && !removed"
                        type="button"
                        variant="pillOutline"
                        class="border-destructive text-destructive hover:bg-destructive hover:text-destructive-foreground"
                        data-test="image-remove"
                        @click="removed = true"
                    >
                        <Trash class="size-4" />
                        {{ t('appearance.remove') }}
                    </Button>
                    <Button
                        v-if="removed"
                        type="button"
                        variant="pillOutline"
                        data-test="image-keep"
                        @click="removed = false"
                    >
                        <Undo2 class="size-4" />
                        {{ t('appearance.keep') }}
                    </Button>
                </div>
                <p
                    v-if="file"
                    class="mt-2 text-xs text-muted-foreground"
                    data-test="image-size"
                >
                    {{
                        t('appearance.size', {
                            size: formatFileSize(file.size),
                        })
                    }}
                </p>
                <InputError :message="error" data-test="image-error" />
            </div>
        </div>
    </div>
</template>
