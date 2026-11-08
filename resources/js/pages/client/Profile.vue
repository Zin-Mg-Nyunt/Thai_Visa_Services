<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Cake,
    Download,
    FileText,
    Lock,
    LogOut,
    Phone,
    UserRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { download } from '@/actions/App/Http/Controllers/Client/DocumentController';
import { destroy } from '@/actions/App/Http/Controllers/Client/SessionController';
import ClientAvatar from '@/components/ClientAvatar.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { formatDate, formatMoney } from '@/lib/format';
import { pageTitle } from '@/lib/pageTitle';
import { localised } from '@/lib/service';
import { paymentStatusKey, serviceStatusKey } from '@/lib/status';
import type { ClientPortalService, ClientProfile } from '@/types';

const props = defineProps<{
    token: string;
    profile: { data: ClientProfile };
    services: { data: ClientPortalService[] };
}>();

const { t } = useI18n();

const person = computed(() => props.profile.data);

const details = computed(() => [
    {
        label: t('clients.form.phone'),
        value: person.value.phone,
        icon: Phone,
    },
    {
        label: t('clients.form.birthday'),
        value: formatDate(person.value.birthday),
        icon: Cake,
    },
    {
        label: t('clients.form.gender'),
        value: t(`clients.gender.${person.value.gender}`),
        icon: UserRound,
    },
]);

const titleOf = (service: { title: string | null; title_my: string | null }) =>
    localised(service.title, service.title_my) || t('services.untitled');

// "Passport – Bath", or only "Passport" when there is no sub-service.
const nameOf = (item: ClientPortalService) =>
    item.sub_service
        ? `${titleOf(item.service)} – ${titleOf(item.sub_service)}`
        : titleOf(item.service);

const moneyRows = (item: ClientPortalService) =>
    item.fee === undefined
        ? []
        : [
              {
                  label: t('clientArea.services.fee'),
                  value: formatMoney(item.fee),
              },
              {
                  label: t('clientArea.services.received'),
                  value: formatMoney(item.received ?? '0.00'),
              },
              {
                  label: t('clientArea.services.remaining'),
                  value: formatMoney(item.remaining ?? '0.00'),
              },
          ];

// A plain link, not an Inertia visit: the answer is a file. The server checks the owner and the lock again on every request.
const downloadUrl = (documentId: number) =>
    download.url({ client: props.token, document: documentId });

const isSigningOut = ref(false);

const signOut = () => {
    router.post(
        destroy.url({ client: props.token }),
        {},
        {
            onStart: () => (isSigningOut.value = true),
            onFinish: () => (isSigningOut.value = false),
        },
    );
};
</script>

<template>
    <Head :title="pageTitle('clientArea.head')" />

    <section class="rounded-2xl border bg-card p-4 shadow-xs sm:p-6">
        <div
            class="flex flex-col items-center gap-3 text-center sm:flex-row sm:gap-4 sm:text-left"
        >
            <ClientAvatar
                :name="person.name"
                :photo-url="person.photo_url"
                class="size-24 text-4xl sm:size-28 sm:text-5xl"
            />
            <div class="min-w-0">
                <h1
                    class="text-xl font-bold wrap-break-word text-brand-dark"
                    data-test="client-welcome"
                >
                    {{ t('clientArea.welcome', { name: person.name }) }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ t('clientArea.intro') }}
                </p>
            </div>
        </div>

        <h2 class="mt-4 text-sm font-semibold text-brand-dark sm:mt-6">
            {{ t('clientArea.detailsTitle') }}
        </h2>
        <dl class="mt-2 divide-y" data-test="client-details">
            <div
                v-for="item in details"
                :key="item.label"
                class="flex items-start justify-between gap-3 py-2 text-sm sm:gap-4 sm:py-3"
            >
                <dt
                    class="flex shrink-0 items-center gap-2 text-muted-foreground"
                >
                    <component :is="item.icon" class="size-4 shrink-0" />
                    {{ item.label }}
                </dt>
                <dd
                    class="min-w-0 text-right font-medium wrap-break-word text-brand-dark"
                >
                    {{ item.value }}
                </dd>
            </div>
        </dl>

        <p class="mt-2 text-xs text-muted-foreground">
            {{ t('clientArea.contactAgency') }}
        </p>

        <h2 class="mt-6 text-sm font-semibold text-brand-dark">
            {{ t('clientArea.services.title') }}
        </h2>
        <ul
            v-if="services.data.length"
            class="mt-2 space-y-3"
            data-test="client-services"
        >
            <li
                v-for="item in services.data"
                :key="item.id"
                class="rounded-xl border p-4 text-sm"
                data-test="client-service"
            >
                <div class="flex items-start justify-between gap-3">
                    <p
                        class="min-w-0 font-semibold wrap-break-word text-brand-dark"
                    >
                        {{ nameOf(item) }}
                    </p>
                    <StatusBadge
                        kind="serviceStatus"
                        :status="serviceStatusKey(item.status)"
                    />
                </div>
                <div v-if="item.payment_status" class="mt-2">
                    <StatusBadge
                        kind="paymentStatus"
                        :status="paymentStatusKey(item.payment_status)"
                    />
                </div>
                <dl
                    v-if="moneyRows(item).length"
                    class="mt-3 divide-y border-t"
                >
                    <div
                        v-for="row in moneyRows(item)"
                        :key="row.label"
                        class="flex items-center justify-between gap-3 py-2"
                    >
                        <dt class="text-muted-foreground">{{ row.label }}</dt>
                        <dd class="font-semibold text-brand-dark">
                            {{ row.value }}
                        </dd>
                    </div>
                </dl>

                <div
                    v-if="item.documents"
                    class="mt-3 border-t pt-3"
                    data-test="client-documents"
                >
                    <h3 class="text-xs font-semibold text-brand-dark">
                        {{ t('clientArea.documents.title') }}
                    </h3>
                    <ul v-if="item.documents.length" class="mt-2 space-y-2">
                        <li
                            v-for="document in item.documents"
                            :key="document.id"
                            class="rounded-lg bg-muted/40 p-3"
                            data-test="client-document"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <p
                                    class="flex min-w-0 items-start gap-2 font-medium wrap-break-word text-brand-dark"
                                >
                                    <FileText
                                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                    />
                                    {{ document.title }}
                                </p>
                                <!-- Downloadable: the download icon is the link. Locked: a lock icon, no link. -->
                                <Button
                                    v-if="document.unlocked"
                                    as-child
                                    variant="outline"
                                    size="icon-sm"
                                    class="shrink-0 border-transparent bg-emerald-100 text-emerald-900 hover:bg-emerald-200"
                                >
                                    <a
                                        :href="downloadUrl(document.id)"
                                        :aria-label="
                                            t('clientArea.documents.download')
                                        "
                                        :title="
                                            t('clientArea.documents.download')
                                        "
                                        data-test="download-document-link"
                                    >
                                        <Download class="size-4" />
                                    </a>
                                </Button>
                                <span
                                    v-else
                                    class="flex size-8 shrink-0 items-center justify-center rounded-md bg-amber-100 text-amber-900"
                                    role="img"
                                    :aria-label="
                                        t('clientArea.documents.locked')
                                    "
                                    :title="t('clientArea.documents.locked')"
                                    data-test="document-state"
                                    data-state="locked"
                                >
                                    <Lock class="size-4" />
                                </span>
                            </div>
                            <p
                                v-if="!document.unlocked"
                                class="mt-2 text-xs text-muted-foreground"
                                data-test="document-locked-message"
                            >
                                {{
                                    t('clientArea.documents.lockedMessage', {
                                        paid: formatMoney(
                                            item.received ?? '0.00',
                                        ),
                                        due: formatMoney(
                                            item.remaining ?? '0.00',
                                        ),
                                    })
                                }}
                            </p>
                        </li>
                    </ul>
                    <p v-else class="mt-2 text-xs text-muted-foreground">
                        {{ t('clientArea.documents.empty') }}
                    </p>
                </div>
            </li>
        </ul>
        <p v-else class="mt-2 text-sm text-muted-foreground">
            {{ t('clientArea.services.empty') }}
        </p>

        <Button
            type="button"
            variant="pillOutline"
            class="mt-4 sm:mt-6"
            :disabled="isSigningOut"
            data-test="client-logout"
            @click="signOut"
        >
            <LogOut class="size-4" />
            {{ t('clientArea.signOut') }}
        </Button>
    </section>
</template>
