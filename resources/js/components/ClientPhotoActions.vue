<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Camera, Trash } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    destroy,
    update,
} from '@/actions/App/Http/Controllers/Admin/ClientPhotoController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { resizePhoto } from '@/lib/resizePhoto';

const props = defineProps<{
    clientId: number;
    hasPhoto: boolean;
}>();

const { t } = useI18n();

const input = ref<HTMLInputElement | null>(null);
const localError = ref('');
const isRemoving = ref(false);

const form = useForm<{ photo: File | null }>({ photo: null });

const error = computed(() => localError.value || form.errors.photo || '');

const choosePhoto = async (event: Event) => {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];

    target.value = '';
    localError.value = '';
    form.clearErrors();

    if (!file) {
        return;
    }

    if (file.type !== 'image/jpeg') {
        localError.value = t('clients.photo.invalidType');

        return;
    }

    try {
        form.photo = await resizePhoto(file);
    } catch {
        localError.value = t('clients.photo.unreadable');

        return;
    }

    form.post(update.url(props.clientId), {
        preserveScroll: true,
        onFinish: () => form.reset(),
    });
};

const removePhoto = () => {
    router.delete(destroy.url(props.clientId), {
        preserveScroll: true,
        onStart: () => (isRemoving.value = true),
        onFinish: () => (isRemoving.value = false),
    });
};
</script>

<template>
    <div data-test="photo-actions">
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
                :disabled="form.processing"
                data-test="photo-upload"
                @click="input?.click()"
            >
                <Camera class="size-4" />
                {{
                    hasPhoto
                        ? t('clients.photo.change')
                        : t('clients.photo.upload')
                }}
            </Button>
            <Button
                v-if="hasPhoto"
                type="button"
                variant="pillOutline"
                class="border-destructive text-destructive hover:bg-destructive hover:text-destructive-foreground"
                :disabled="isRemoving"
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
</template>
