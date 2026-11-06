<script setup lang="ts">
import { Camera, Trash } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ClientAvatar from '@/components/ClientAvatar.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { resizePhoto } from '@/lib/resizePhoto';

/**
 * Chooses a profile photo to send with a form, instead of uploading it straight away like ClientPhotoActions.
 * The photo is checked and made smaller on the device, and a preview shows until the form is saved.
 */
const props = defineProps<{
    /** The client's name, for the initial shown while there is no photo. */
    name: string;
    /** The server's error for the photo, if any. */
    error?: string;
}>();

const photo = defineModel<File | null>({ required: true });

const { t } = useI18n();

const input = ref<HTMLInputElement | null>(null);
const localError = ref('');
const isPreparing = ref(false);
const previewUrl = ref<string | null>(null);

const error = computed(() => localError.value || props.error || '');

// One preview link at a time; the old one is released so the browser can free the image.
watch(
    photo,
    (file) => {
        if (previewUrl.value) {
            URL.revokeObjectURL(previewUrl.value);
        }

        previewUrl.value = file ? URL.createObjectURL(file) : null;
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
    }
});

const choosePhoto = async (event: Event) => {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];

    target.value = '';
    localError.value = '';

    if (!file) {
        return;
    }

    if (file.type !== 'image/jpeg') {
        localError.value = t('clients.photo.invalidType');

        return;
    }

    isPreparing.value = true;

    try {
        photo.value = await resizePhoto(file);
    } catch {
        localError.value = t('clients.photo.unreadable');
    } finally {
        isPreparing.value = false;
    }
};

const removePhoto = () => {
    photo.value = null;
    localError.value = '';
};
</script>

<template>
    <div class="flex items-center gap-4" data-test="photo-picker">
        <ClientAvatar
            :name="name || '?'"
            :photo-url="previewUrl"
            class="size-16 text-xl"
        />
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <input
                    ref="input"
                    type="file"
                    accept="image/jpeg"
                    class="sr-only"
                    tabindex="-1"
                    data-test="photo-input"
                    @change="choosePhoto"
                />
                <Button
                    type="button"
                    variant="pillOutline"
                    :disabled="isPreparing"
                    data-test="photo-upload"
                    @click="input?.click()"
                >
                    <Camera class="size-4" />
                    {{
                        photo
                            ? t('clients.photo.change')
                            : t('clients.photo.upload')
                    }}
                </Button>
                <Button
                    v-if="photo"
                    type="button"
                    variant="pillOutline"
                    class="border-destructive text-destructive hover:bg-destructive hover:text-destructive-foreground"
                    :aria-label="t('clients.photo.remove')"
                    :title="t('clients.photo.remove')"
                    data-test="photo-remove"
                    @click="removePhoto"
                >
                    <Trash class="size-4" />
                    <span class="hidden sm:inline">{{
                        t('clients.photo.remove')
                    }}</span>
                </Button>
            </div>
            <p class="mt-1 text-xs text-muted-foreground">
                {{ t('clients.photo.hint') }}
            </p>
            <InputError :message="error" data-test="photo-error" />
        </div>
    </div>
</template>
