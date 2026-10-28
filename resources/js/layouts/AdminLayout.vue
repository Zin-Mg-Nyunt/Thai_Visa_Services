<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Menu } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminNav from '@/components/AdminNav.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Toaster } from '@/components/ui/sonner';
import { dashboard } from '@/routes';

const { t } = useI18n();
const isMenuOpen = ref(false);
</script>

<template>
    <div class="min-h-svh bg-background">
        <aside
            class="fixed inset-y-0 left-0 hidden w-60 flex-col gap-8 bg-sidebar p-4 lg:flex"
        >
            <Link :href="dashboard()" class="w-fit">
                <AppLogoIcon variant="white" class="size-12 rounded-xl" />
            </Link>
            <AdminNav class="min-h-0 flex-1" />
        </aside>

        <header
            class="sticky top-0 z-30 flex items-center justify-between bg-sidebar px-4 py-3 lg:hidden"
        >
            <Link :href="dashboard()">
                <AppLogoIcon variant="white" class="size-10 rounded-lg" />
            </Link>
            <Sheet v-model:open="isMenuOpen">
                <SheetTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
                        :aria-label="t('admin.openMenu')"
                    >
                        <Menu />
                    </Button>
                </SheetTrigger>
                <SheetContent
                    side="left"
                    class="w-64 border-sidebar-border bg-sidebar p-4"
                >
                    <SheetTitle class="sr-only">{{
                        t('admin.openMenu')
                    }}</SheetTitle>
                    <AppLogoIcon
                        variant="white"
                        class="mb-8 size-12 rounded-xl"
                    />
                    <AdminNav
                        class="h-[calc(100%-5rem)]"
                        @navigate="isMenuOpen = false"
                    />
                </SheetContent>
            </Sheet>
        </header>

        <main class="min-w-0 p-4 md:p-8 lg:pl-68">
            <slot />
        </main>
        <Toaster />
    </div>
</template>
