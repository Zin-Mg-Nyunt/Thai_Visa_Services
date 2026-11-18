<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    Download,
    EllipsisVertical,
    FileText,
    Lock,
    LockOpen,
    Pencil,
    Plus,
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime, formatFileSize } from '@/lib/format';
import type { ClientDocument } from '@/types';

const props = withDefaults(
    defineProps<{
        clientId: number;
        clientServiceId: number;
        serviceName: string;
        documents: ClientDocument[];
        /** Cancelled Job Search engagement: view and download only. */
        readOnly?: boolean;
        /** False for Job Search documents: no release switch, they are never shown to the client. */
        releasable?: boolean;
    }>(),
    { readOnly: false, releasable: true },
);

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

// Edit: the title and, if wanted, a new file, in one dialog. The title is saved first, then the file; each keeps its own
// checks on the server.
const editing = ref<ClientDocument | null>(null);
const isEditOpen = ref(false);
const renameForm = useForm<{ title: string }>({ title: '' });
const replaceForm = useForm<{ file: File | null }>({ file: null });
const replaceInput = ref<HTMLInputElement | null>(null);

const openEdit = (document: ClientDocument) => {
    editing.value = document;
    renameForm.title = document.title;
    renameForm.clearErrors();
    replaceForm.reset();
    replaceForm.clearErrors();

    if (replaceInput.value) {
        replaceInput.value.value = '';
    }

    isEditOpen.value = true;
};

const pickReplacement = (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;

    replaceForm.file = file;
    replaceForm.clearErrors('file');

    if (file && tooLarge(file)) {
        replaceForm.setError('file', t('documents.errors.tooLarge'));
    }
};

const sendReplacement = (document: ClientDocument) => {
    const route = replace(target(document));

    replaceForm.submit(route.method, route.url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => (isEditOpen.value = false),
    });
};

const saveEdit = () => {
    const document = editing.value;

    if (!document) {
        return;
    }

    if (replaceForm.file && tooLarge(replaceForm.file)) {
        replaceForm.setError('file', t('documents.errors.tooLarge'));

        return;
    }

    if (renameForm.title === document.title) {
        if (replaceForm.file) {
            sendReplacement(document);
        } else {
            isEditOpen.value = false;
        }

        return;
    }

    const route = update(target(document));

    renameForm.submit(route.method, route.url, {
        preserveScroll: true,
        onSuccess: () => {
            if (replaceForm.file) {
                sendReplacement(document);
            } else {
                isEditOpen.value = false;
            }
        },
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

const toggleLabel = (document: ClientDocument) =>
    `${t(`documents.state.${stateKey(document)}`)}: ${
        document.client_can_download
            ? t('documents.withdraw')
            : t('documents.release')
    }`;
</script>

<template>
    <div class="mt-4 border-t pt-4" data-test="document-list">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h4 class="text-sm font-semibold text-brand-dark">
                {{ t('documents.title') }}
            </h4>
            <Button
                v-if="!readOnly"
                variant="pillOutline"
                size="sm"
                data-test="upload-document-button"
                @click="isUploadOpen = true"
            >
                <Plus class="size-4" />
                {{ t('documents.upload') }}
            </Button>
        </div>

        <p
            v-if="readOnly"
            class="mt-1 text-xs text-muted-foreground"
            data-test="documents-read-only"
        >
            {{ t('documents.readOnly') }}
        </p>

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
                    <!-- From sm up: icon buttons. Below sm: one kebab menu with the same actions. -->
                    <div class="hidden shrink-0 gap-1 sm:flex">
                        <Button
                            v-if="!readOnly"
                            variant="ghost"
                            size="icon-sm"
                            :aria-label="t('common.edit')"
                            :title="t('common.edit')"
                            data-test="edit-document-button"
                            @click="openEdit(document)"
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
                            v-if="!readOnly"
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
                        <Button
                            v-if="releasable"
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            :class="
                                document.unlocked
                                    ? 'bg-emerald-100 text-emerald-900 hover:bg-emerald-200'
                                    : 'bg-amber-100 text-amber-900 hover:bg-amber-200'
                            "
                            :aria-label="toggleLabel(document)"
                            :title="toggleLabel(document)"
                            :data-state="
                                document.unlocked ? 'downloadable' : 'locked'
                            "
                            data-test="document-state"
                            @click="
                                release(document, !document.client_can_download)
                            "
                        >
                            <LockOpen v-if="document.unlocked" class="size-4" />
                            <Lock v-else class="size-4" />
                        </Button>
                    </div>

                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                class="shrink-0 sm:hidden"
                                :aria-label="t('common.actions')"
                                :title="t('common.actions')"
                                data-test="document-actions-menu"
                            >
                                <EllipsisVertical class="size-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="min-w-0">
                            <DropdownMenuItem
                                v-if="!readOnly"
                                :aria-label="t('common.edit')"
                                :title="t('common.edit')"
                                class="justify-center"
                                data-test="edit-document-button"
                                @select="openEdit(document)"
                            >
                                <Pencil />
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="releasable"
                                :aria-label="toggleLabel(document)"
                                :title="toggleLabel(document)"
                                :data-state="
                                    document.unlocked
                                        ? 'downloadable'
                                        : 'locked'
                                "
                                class="justify-center"
                                data-test="document-state"
                                @select="
                                    release(
                                        document,
                                        !document.client_can_download,
                                    )
                                "
                            >
                                <LockOpen v-if="document.unlocked" />
                                <Lock v-else />
                            </DropdownMenuItem>
                            <DropdownMenuItem as-child class="justify-center">
                                <a
                                    :href="download.url(target(document))"
                                    :aria-label="t('documents.download')"
                                    :title="t('documents.download')"
                                    data-test="download-document-link"
                                >
                                    <Download />
                                </a>
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="!readOnly"
                                variant="destructive"
                                :aria-label="t('common.delete')"
                                :title="t('common.delete')"
                                class="justify-center"
                                data-test="delete-document-button"
                                @select="askDelete(document)"
                            >
                                <Trash />
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
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

    <Dialog v-model:open="isEditOpen">
        <DialogContent>
            <form class="grid gap-4" @submit.prevent="saveEdit">
                <DialogHeader>
                    <DialogTitle>{{
                        t('documents.dialog.rename')
                    }}</DialogTitle>
                    <DialogDescription>
                        {{ serviceName }} · {{ t('documents.limits') }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="edit-title">{{
                        t('documents.fields.title')
                    }}</Label>
                    <Input
                        id="edit-title"
                        v-model="renameForm.title"
                        maxlength="150"
                        :aria-invalid="!!renameForm.errors.title"
                    />
                    <InputError :message="renameForm.errors.title" />
                </div>

                <div class="grid gap-2">
                    <Label for="replace-file">{{
                        t('documents.replace.optional')
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
                    <p class="text-xs text-muted-foreground">
                        {{ t('documents.replace.hint') }}
                    </p>
                    <InputError :message="replaceForm.errors.file" />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="pillOutline"
                        @click="isEditOpen = false"
                    >
                        {{ t('common.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        variant="pill"
                        :disabled="
                            renameForm.processing || replaceForm.processing
                        "
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
