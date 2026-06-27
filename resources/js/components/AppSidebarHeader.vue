<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useAppearance } from '@/composables/useAppearance';
import type { BreadcrumbItemType } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Bell,
    MessageSquare,
    Moon,
    Package,
    Sun,
} from 'lucide-vue-next';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import { computed, onMounted, ref } from 'vue';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItemType[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const { updateAppearance } = useAppearance();
const isDark = ref(false);
const page = usePage();

const stockAlertes = computed(
    () =>
        (page.props as any).stock_alertes ?? {
            ruptures: 0,
            faibles: 0,
            total: 0,
        },
);
const _produits = computed(() => (page.props as any).produits_alertes ?? []);
const contactMessagesNonLus = computed(
    () => (page.props as any).contact_messages_non_lus ?? 0,
);

function syncThemeState() {
    if (typeof document === 'undefined') return;
    isDark.value = document.documentElement.classList.contains('dark');
}

function toggleTheme() {
    updateAppearance(isDark.value ? 'light' : 'dark');
    syncThemeState();
}

onMounted(() => {
    syncThemeState();
});
</script>

<template>
    <header
        class="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
    >
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs
                    :breadcrumbs="breadcrumbs"
                    class="hidden sm:block"
                />
            </template>
        </div>

        <div class="ml-auto flex items-center gap-1">
            <!-- Recherche globale (UI seulement pour l'instant) -->
            <IconField>
                <InputIcon class="pointer-events-none">
                    <svg
                        class="h-4 w-4 text-muted-foreground"
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                        />
                    </svg>
                </InputIcon>
                <InputText
                    placeholder="Recherche"
                    class="w-48 rounded-full text-sm sm:w-full"
                />
            </IconField>

            <Button
                variant="ghost"
                size="icon"
                class="relative h-9 w-9"
                @click="toggleTheme"
            >
                <Sun v-if="isDark" class="h-5 w-5" />
                <Moon v-else class="h-5 w-5" />
                <span class="sr-only">Changer le theme</span>
            </Button>

            <!-- Cloche notifications -->
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="relative h-9 w-9"
                    >
                        <Bell class="h-5 w-5" />
                        <span
                            v-if="
                                stockAlertes.total + contactMessagesNonLus > 0
                            "
                            class="absolute top-1 right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-0.5 text-[10px] font-bold text-destructive-foreground"
                            >{{
                                stockAlertes.total + contactMessagesNonLus
                            }}</span
                        >
                        <span class="sr-only">Notifications</span>
                    </Button>
                </DropdownMenuTrigger>

                <DropdownMenuContent align="end" class="w-80">
                    <!-- En-tête -->
                    <div
                        class="flex items-center justify-between border-b px-4 py-3"
                    >
                        <span class="text-sm font-semibold">Notifications</span>
                        <span
                            v-if="
                                stockAlertes.total + contactMessagesNonLus > 0
                            "
                            class="rounded-full bg-destructive px-2 py-0.5 text-[10px] font-bold text-destructive-foreground"
                        >
                            {{ stockAlertes.total + contactMessagesNonLus }}
                        </span>
                    </div>

                    <!-- Aucune notif -->
                    <div
                        v-if="stockAlertes.total + contactMessagesNonLus === 0"
                        class="flex flex-col items-center gap-2 py-8 text-center text-sm text-muted-foreground"
                    >
                        <Bell class="h-8 w-8 opacity-20" />
                        <p>Aucune notification</p>
                    </div>

                    <!-- Ruptures -->
                    <div v-if="stockAlertes.ruptures > 0" class="border-b">
                        <div
                            class="flex items-center gap-2 bg-destructive/5 px-4 py-2"
                        >
                            <AlertTriangle
                                class="h-3.5 w-3.5 text-destructive"
                            />
                            <span class="text-xs font-semibold text-destructive"
                                >Rupture de stock ({{
                                    stockAlertes.ruptures
                                }})</span
                            >
                        </div>
                        <Link
                            href="/produits"
                            class="block px-4 py-2.5 text-xs text-muted-foreground transition-colors hover:bg-muted/50"
                        >
                            Voir les produits en rupture →
                        </Link>
                    </div>

                    <!-- Stocks faibles -->
                    <div v-if="stockAlertes.faibles > 0">
                        <div
                            class="flex items-center gap-2 bg-amber-50 px-4 py-2 dark:bg-amber-950/20"
                        >
                            <AlertTriangle class="h-3.5 w-3.5 text-amber-500" />
                            <span
                                class="text-xs font-semibold text-amber-700 dark:text-amber-400"
                                >Stock faible ({{ stockAlertes.faibles }})</span
                            >
                        </div>
                        <Link
                            href="/produits"
                            class="block px-4 py-2.5 text-xs text-muted-foreground transition-colors hover:bg-muted/50"
                        >
                            Voir les produits en alerte →
                        </Link>
                    </div>

                    <!-- Messages contact -->
                    <div v-if="contactMessagesNonLus > 0" class="border-b">
                        <div
                            class="flex items-center gap-2 bg-blue-50 px-4 py-2 dark:bg-blue-950/20"
                        >
                            <MessageSquare class="h-3.5 w-3.5 text-blue-500" />
                            <span
                                class="text-xs font-semibold text-blue-700 dark:text-blue-400"
                                >Messages contact ({{
                                    contactMessagesNonLus
                                }})</span
                            >
                        </div>
                        <Link
                            href="/contact-messages"
                            class="block px-4 py-2.5 text-xs text-muted-foreground transition-colors hover:bg-muted/50"
                        >
                            Voir les messages →
                        </Link>
                    </div>

                    <!-- Footer -->
                    <div
                        v-if="stockAlertes.total + contactMessagesNonLus > 0"
                        class="border-t px-4 py-2"
                    >
                        <Link
                            href="/produits"
                            class="flex items-center gap-1.5 text-xs font-medium text-primary hover:underline"
                        >
                            <Package class="h-3.5 w-3.5" />
                            Gérer les produits
                        </Link>
                    </div>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </header>
</template>
