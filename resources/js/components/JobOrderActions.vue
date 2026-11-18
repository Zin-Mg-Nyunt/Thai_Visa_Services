<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Ban, CircleCheck, EllipsisVertical, Pencil } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { closePreview } from '@/actions/App/Http/Controllers/Admin/JobOrderController';
import CloseJobOrderDialog from '@/components/CloseJobOrderDialog.vue';
import ResolveAndCloseDialog from '@/components/ResolveAndCloseDialog.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { edit } from '@/routes/admin/job-search';
import type { ClosePreview, JobOrder } from '@/types';

const props = defineProps<{
    jobOrder: JobOrder;
    /**
     * icons: icon buttons (the table row). menu: one kebab menu with icon-only items (the mobile card).
     * labelled: Done and Cancel buttons with text (the edit page).
     */
    layout: 'icons' | 'menu' | 'labelled';
    /** Set on the edit page: a closed order's edit page is refused, so after closing the admin goes to the list. */
    fromEdit?: boolean;
}>();

const { t } = useI18n();

const closing = ref<'done' | 'cancelled' | null>(null);
const isOpen = ref(false);
const preview = ref<ClosePreview>({ open: [], deleted_open: [] });

/**
 * Closing starts by asking the server what is open on the order. Open applications of people who are not deleted must be
 * resolved first, in the resolution dialog; with none, the plain confirmation is enough. If the answer cannot be read, the
 * plain confirmation is shown and the server, which checks again, refuses the close while anything is open.
 */
const ask = async (status: 'done' | 'cancelled') => {
    closing.value = status;

    try {
        const response = await fetch(closePreview.url(props.jobOrder.id), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        preview.value = response.ok
            ? ((await response.json()) as ClosePreview)
            : { open: [], deleted_open: [] };
    } catch {
        preview.value = { open: [], deleted_open: [] };
    }

    isOpen.value = true;
};
</script>

<template>
    <!-- A closed order is frozen: no edit and no close buttons. -->
    <div v-if="jobOrder.can_edit" class="inline-flex items-center gap-1">
        <template v-if="layout === 'icons'">
            <Button
                as-child
                variant="ghost"
                size="icon-sm"
                :aria-label="t('common.edit')"
                :title="t('common.edit')"
            >
                <Link :href="edit(jobOrder.id)" data-test="edit-job-order-link">
                    <Pencil class="size-4" />
                </Link>
            </Button>
            <Button
                variant="ghost"
                size="icon-sm"
                class="text-emerald-700"
                :aria-label="t('jobOrders.actions.done')"
                :title="t('jobOrders.actions.done')"
                data-test="done-job-order-button"
                @click="ask('done')"
            >
                <CircleCheck class="size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon-sm"
                class="text-destructive"
                :aria-label="t('jobOrders.actions.cancelled')"
                :title="t('jobOrders.actions.cancelled')"
                data-test="cancel-job-order-button"
                @click="ask('cancelled')"
            >
                <Ban class="size-4" />
            </Button>
        </template>

        <DropdownMenu v-else-if="layout === 'menu'">
            <DropdownMenuTrigger as-child>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    :aria-label="t('common.actions')"
                    :title="t('common.actions')"
                    data-test="job-order-actions-menu"
                >
                    <EllipsisVertical class="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="min-w-0">
                <DropdownMenuItem as-child class="justify-center">
                    <Link
                        :href="edit(jobOrder.id)"
                        :aria-label="t('common.edit')"
                        :title="t('common.edit')"
                        data-test="edit-job-order-link"
                    >
                        <Pencil />
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem
                    :aria-label="t('jobOrders.actions.done')"
                    :title="t('jobOrders.actions.done')"
                    class="justify-center"
                    data-test="done-job-order-button"
                    @select="ask('done')"
                >
                    <CircleCheck />
                </DropdownMenuItem>
                <DropdownMenuItem
                    variant="destructive"
                    :aria-label="t('jobOrders.actions.cancelled')"
                    :title="t('jobOrders.actions.cancelled')"
                    class="justify-center"
                    data-test="cancel-job-order-button"
                    @select="ask('cancelled')"
                >
                    <Ban />
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>

        <template v-else>
            <Button
                variant="pill"
                data-test="done-job-order-button"
                @click="ask('done')"
            >
                <CircleCheck class="size-4" />
                {{ t('jobOrders.actions.done') }}
            </Button>
            <Button
                variant="destructive"
                class="rounded-full px-6"
                data-test="cancel-job-order-button"
                @click="ask('cancelled')"
            >
                <Ban class="size-4" />
                {{ t('jobOrders.actions.cancelled') }}
            </Button>
        </template>

        <ResolveAndCloseDialog
            v-if="closing && preview.open.length"
            v-model:open="isOpen"
            :job-order-id="props.jobOrder.id"
            :company-name="props.jobOrder.company_name"
            :status="closing"
            :open-applications="preview.open"
            :deleted-open="preview.deleted_open"
            :from-edit="fromEdit"
        />
        <CloseJobOrderDialog
            v-else-if="closing"
            v-model:open="isOpen"
            :job-order-id="props.jobOrder.id"
            :company-name="props.jobOrder.company_name"
            :status="closing"
            :deleted-open="preview.deleted_open"
            :from-edit="fromEdit"
        />
    </div>
</template>
