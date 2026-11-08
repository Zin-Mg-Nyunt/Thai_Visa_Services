<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    Download,
    FileText,
    Lock,
    LockOpen,
    Pencil,
    Plus,
    RefreshCw,
    Save,
    Trash,
    Upload,
} from '@lucide/vue';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    destroy,
    download,
    replace,
    store,
    update,
} from '@/actions/App/Http/Controllers/Admin/DocumentController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime, formatFileSize } from '@/lib/format';
import type { ClientDocument } from '@/types';

const props = defineProps<{
    clientId: number;
    clientServiceId: number;
    serviceName: string;
    documents: ClientDocument[];
}>();

const { t } = useI18n();

const target = (document: ClientDocument) => ({
    client: props.clientId,
    clientService: props.clientServiceId,
    document: document.id,
});

// Upload
const isUploadOpen = ref(false);
const uploadForm = useForm<{
    title: string;
    file: File | null;
}>({ title: '', file: null });
const fileInput = ref<HTMLInputElement | null>(null);

watch(isUploadOpen, (isOpen) => {
    if (isOpen) {
        uploadForm.reset();
        uploadForm.clearErrors();

        if (fileInput.value) {
            fileInput.value.value = '';
        }
    }
});

// The same limits as the server: a PDF up to 5 MB, a JPG or PNG up to 2 MB. This only saves a failed trip; the server
// still judges the real file. A file over the limit is never sent, which also avoids the server's own size error.
const PDF_LIMIT = 5 * 1024 * 1024;
const IMAGE_LIMIT = 2 * 1024 * 1024;

const tooLarge = (file: File) =>
    file.size > (file.type.startsWith('image/') ? IMAGE_LIMIT : PDF_LIMIT);

const pickFile = (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;

    uploadForm.file = file;
    uploadForm.clearErrors('file');

    if (file && tooLarge(file)) {
        uploadForm.setError('file', t('documents.errors.tooLarge'));
    }
};

const upload = () => {
    if (uploadForm.file && tooLarge(uploadForm.file)) {
        uploadForm.setError('file', t('documents.errors.tooLarge'));

        return;
    }

    const route = store({
        client: props.clientId,
        clientService: props.clientServiceId,
    });

    uploadForm.submit(route.method, route.url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => (isUploadOpen.value = false),
    });
};

// Replace the file
const replacing = ref<ClientDocument | null>(null);
const isReplaceOpen = ref(false);
const replaceForm = useForm<{ file: File | null }>({ file: null });
const replaceInput = ref<HTMLInputElement | null>(null);

const openReplace = (document: ClientDocument) => {
    replacing.value = document;
    replaceForm.reset();
    replaceForm.clearErrors();

    if (replaceInput.value) {
        replaceInput.value.value = '';
    }

    isReplaceOpen.value = true;
};

const pickReplacement = (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;

    replaceForm.file = file;
    replaceForm.clearErrors('file');

    if (file && tooLarge(file)) {
        replaceForm.setError('file', t('documents.errors.tooLarge'));
    }
};

const submitReplacement = () => {
    if (!replacing.value) {
        return;
    }

    if (replaceForm.file && tooLarge(replaceForm.file)) {
        replaceForm.setError('file', t('documents.errors.tooLarge'));

        return;
    }

    const route = replace(target(replacing.value));

    replaceForm.submit(route.method, route.url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => (isReplaceOpen.value = false),
    });
};

// Rename
const renaming = ref<ClientDocument | null>(null);
const isRenameOpen = ref(false);
const renameForm = useForm<{ title: string }>({ title: '' });

const openRename = (document: ClientDocument) => {
    renaming.value = document;
    renameForm.title = document.title;
    renameForm.clearErrors();
    isRenameOpen.value = true;
};

const rename = () => {
    if (!renaming.value) {
        return;
    }

    const route = update(target(renaming.value));

    renameForm.submit(route.method, route.url, {
        preserveScroll: true,
        onSuccess: () => (isRenameOpen.value = false),
    });
};

// Release
const release = (document: ClientDocument, value: boolean) => {
    router.patch(
        update.url(target(document)),
        { client_can_download: value },
        { preserveScroll: true },
    );
};

// Delete
const deleting = ref<ClientDocument | null>(null);
const isDeleteOpen = ref(false);
const isDeleting = ref(false);

const askDelete = (document: ClientDocument) => {
    deleting.value = document;
    isDeleteOpen.value = true;
};

const remove = () => {
    if (!deleting.value) {
        return;
    }

    router.delete(destroy.url(target(deleting.value)), {
        preserveScroll: true,
        onStart: () => (isDeleting.value = true),
        onFinish: () => (isDeleting.value = false),
        onSuccess: () => (isDeleteOpen.value = false),
    });
};

const stateKey = (document: ClientDocument) =>
    document.client_can_download
        ? 'released'
        : document.unlocked
          ? 'unlockedByPayment'
          : 'locked';
</script>

<template>
    <div class="mt-4 border-t pt-4" data-test="document-list">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h4 class="text-sm font-semibold text-brand-dark">
                {{ t('documents.title') }}
            </h4>
            <Button
                variant="pillOutline"
                size="sm"
                data-test="upload-document-button"
                @click="isUploadOpen = true"
            >
                <Plus class="size-4" />
                {{ t('documents.upload') }}
            </Button>
        </div>

        <ul v-if="documents.length" class="mt-3 divide-y">
            <li
                v-for="document in documents"
                :key="document.id"
                class="flex flex-col gap-2 py-3 text-sm"
                data-test="document-row"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-2">
                        <FileText
                            class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        />
                        <div class="min-w-0">
                            <p
                                class="font-medium wrap-break-word text-brand-dark"
                            >
                                {{ document.title }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ formatFileSize(document.size) }}
                                <template v-if="document.created_at">
                                    · {{ formatDateTime(document.created_at) }}
                                </template>
                            </p>
                        </div>
                    </div>
                    <div class="flex shrink-0 gap-1">
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            :aria-label="t('common.edit')"
                            :title="t('common.edit')"
                            data-test="rename-document-button"
                            @click="openRename(document)"
                        >
                            <Pencil class="size-4" />
                        </Button>
                        <Button
                            as-child
                            variant="ghost"
                            size="icon-sm"
                            :aria-label="t('documents.download')"
                            :title="t('documents.download')"
                            data-test="download-document-link"
                        >
                            <a :href="download.url(target(document))">
                                <Download class="size-4" />
                            </a>
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            :aria-label="t('documents.replace.button')"
                            :title="t('documents.replace.button')"
                            data-test="replace-document-button"
                            @click="openReplace(document)"
                        >
                            <RefreshCw class="size-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            class="text-destructive"
                            :aria-label="t('common.delete')"
                            :title="t('common.delete')"
                            data-test="delete-document-button"
                            @click="askDelete(document)"
                        >
                            <Trash class="size-4" />
                        </Button>
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2">
                    <Label
                        class="flex items-center gap-2 text-xs"
                        :for="`release-${document.id}`"
                    >
                        <Checkbox
                            :id="`release-${document.id}`"
                            :model-value="document.client_can_download"
                            data-test="release-document-checkbox"
                            @update:model-value="
                                (value) => release(document, value === true)
                            "
                        />
                        {{ t('documents.release') }}
                    </Label>
                    <!-- Unlocked for the client (released or fully paid): open lock icon. Locked: lock icon. -->
                    <span
                        class="flex size-7 items-center justify-center rounded-md"
                        :class="
                            document.unlocked
                                ? 'bg-emerald-100 text-emerald-900'
                                : 'bg-amber-100 text-amber-900'
                        "
                        role="img"
                        :aria-label="t(`documents.state.${stateKey(document)}`)"
                        :title="t(`documents.state.${stateKey(document)}`)"
                        :data-state="
                            document.unlocked ? 'downloadable' : 'locked'
                        "
                        data-test="document-state"
                    >
                        <LockOpen v-if="document.unlocked" class="size-4" />
                        <Lock v-else class="size-4" />
                    </span>
                </div>
            </li>
        </ul>
        <p v-else class="mt-3 text-sm text-muted-foreground">
            {{ t('documents.empty') }}
        </p>
    </div>

    <Dialog v-model:open="isUploadOpen">
        <DialogContent>
            <form class="grid gap-4" @submit.prevent="upload">
                <DialogHeader>
                    <DialogTitle>{{
                        t('documents.dialog.upload')
                    }}</DialogTitle>
                    <DialogDescription>
                        {{ serviceName }} · {{ t('documents.limits') }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label :for="`doc-title-${clientServiceId}`">{{
                        t('documents.fields.title')
                    }}</Label>
                    <Input
                        :id="`doc-title-${clientServiceId}`"
                        v-model="uploadForm.title"
                        maxlength="150"
                        :aria-invalid="!!uploadForm.errors.title"
                    />
                    <InputError :message="uploadForm.errors.title" />
                </div>

                <div class="grid gap-2">
                    <Label :for="`doc-file-${clientServiceId}`">{{
                        t('documents.fields.file')
                    }}</Label>
                    <Input
                        :id="`doc-file-${clientServiceId}`"
                        ref="fileInput"
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                        :aria-invalid="!!uploadForm.errors.file"
                        data-test="document-file-input"
                        @change="pickFile"
                    />
                    <InputError :message="uploadForm.errors.file" />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="pillOutline"
                        @click="isUploadOpen = false"
                    >
                        {{ t('common.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        variant="pill"
                        :disabled="uploadForm.processing"
                        data-test="save-document-button"
                    >
                        <Upload class="size-4" />
                        {{ t('documents.upload') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="isReplaceOpen">
        <DialogContent>
            <form class="grid gap-4" @submit.prevent="submitReplacement">
                <DialogHeader>
                    <DialogTitle>{{
                        t('documents.replace.title')
                    }}</DialogTitle>
                    <DialogDescription>
                        {{ replacing?.title }} · {{ t('documents.limits') }}
                    </DialogDescription>
                </DialogHeader>

                <p class="text-sm text-muted-foreground">
                    {{ t('documents.replace.hint') }}
                </p>

                <div class="grid gap-2">
                    <Label for="replace-file">{{
                        t('documents.fields.file')
                    }}</Label>
                    <Input
                        id="replace-file"
                        ref="replaceInput"
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                        :aria-invalid="!!replaceForm.errors.file"
                        data-test="replace-file-input"
                        @change="pickReplacement"
                    />
                    <InputError :message="replaceForm.errors.file" />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="pillOutline"
                        @click="isReplaceOpen = false"
                    >
                        {{ t('common.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        variant="pill"
                        :disabled="replaceForm.processing || !replaceForm.file"
                        data-test="save-replace-button"
                    >
                        <Upload class="size-4" />
                        {{ t('documents.replace.confirm') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="isRenameOpen">
        <DialogContent>
            <form class="grid gap-4" @submit.prevent="rename">
                <DialogHeader>
                    <DialogTitle>{{
                        t('documents.dialog.rename')
                    }}</DialogTitle>
                    <DialogDescription>{{ serviceName }}</DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="rename-title">{{
                        t('documents.fields.title')
                    }}</Label>
                    <Input
                        id="rename-title"
                        v-model="renameForm.title"
                        maxlength="150"
                        :aria-invalid="!!renameForm.errors.title"
                    />
                    <InputError :message="renameForm.errors.title" />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="pillOutline"
                        @click="isRenameOpen = false"
                    >
                        {{ t('common.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        variant="pill"
                        :disabled="renameForm.processing"
                        data-test="save-rename-button"
                    >
                        <Save class="size-4" />
                        {{ t('common.save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        v-model:open="isDeleteOpen"
        :title="t('documents.delete.title')"
        :description="
            t('documents.delete.description', {
                title: deleting?.title ?? '',
            })
        "
        :processing="isDeleting"
        @confirm="remove"
    />
</template>
