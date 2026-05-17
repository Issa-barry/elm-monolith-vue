<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAppearance } from '@/composables/useAppearance';
import { home, logout } from '@/routes';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Bell,
    ChevronDown,
    LogOut,
    Menu,
    Moon,
    Sun,
    User,
    X,
} from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';

const page = usePage();
const user = computed(() => (page.props as any).auth.user);
const currentUrl = computed(() => page.url);
const { updateAppearance } = useAppearance();
const isDark = ref(false);
const isMobileMenuOpen = ref(false);
const notificationsCount = computed(
    () => (page.props as any).client_notifications_count ?? 0,
);
const userDisplayName = computed(() => {
    const prenom = (user.value?.prenom ?? '').trim();
    const nom = (user.value?.nom ?? '').trim();

    if (prenom || nom) {
        return [prenom, nom].filter(Boolean).join(' ');
    }

    return user.value?.name ?? 'Mon compte';
});

const navItems = [
    { label: 'Accueil', href: '/client/dashboard' },
    { label: 'Gains', href: '/client/gains' },
    { label: 'Vehicules', href: '/client/vehicules' },
];

function isActive(href: string): boolean {
    return currentUrl.value === href || currentUrl.value.startsWith(`${href}?`);
}

function syncThemeState() {
    if (typeof document === 'undefined') {
        return;
    }

    isDark.value = document.documentElement.classList.contains('dark');
}

function toggleTheme() {
    updateAppearance(isDark.value ? 'light' : 'dark');
    syncThemeState();
}

function openProfile() {
    router.flushAll();
    router.visit('/client/profile');
}

function handleLogout() {
    router.flushAll();
    router.post(logout().url);
}

function closeMobileMenu() {
    isMobileMenuOpen.value = false;
}

function toggleMobileMenu() {
    isMobileMenuOpen.value = !isMobileMenuOpen.value;
}

function openProfileFromMobile() {
    closeMobileMenu();
    openProfile();
}

function handleLogoutFromMobile() {
    closeMobileMenu();
    handleLogout();
}

onMounted(() => {
    syncThemeState();
});

watch(currentUrl, () => {
    closeMobileMenu();
});
</script>

<template>
    <div class="flex min-h-screen flex-col bg-background">
        <header
            class="sticky top-0 z-50 border-b border-border bg-background/95 backdrop-blur"
        >
            <div class="mx-auto max-w-5xl px-4">
                <div
                    class="flex h-14 items-center justify-between lg:grid lg:grid-cols-3 lg:items-center"
                >
                    <Link
                        :href="home()"
                        class="flex shrink-0 items-center gap-2 transition-opacity hover:opacity-80"
                    >
                        <AppLogoIcon
                            class="h-7 w-7 fill-current text-primary"
                        />
                        <span class="font-semibold">Eau la maman</span>
                    </Link>

                    <nav class="hidden justify-center lg:flex">
                        <div
                            class="flex items-center gap-1 overflow-x-auto whitespace-nowrap"
                        >
                            <Link
                                v-for="item in navItems"
                                :key="item.href"
                                :href="item.href"
                                class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
                                :class="
                                    isActive(item.href)
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:bg-secondary hover:text-foreground'
                                "
                            >
                                {{ item.label }}
                            </Link>
                        </div>
                    </nav>

                    <div class="flex shrink-0 items-center justify-end gap-1">
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

                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="relative h-9 w-9"
                                >
                                    <Bell class="h-5 w-5" />
                                    <span
                                        v-if="notificationsCount > 0"
                                        class="absolute top-1 right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-0.5 text-[10px] font-bold text-destructive-foreground"
                                    >
                                        {{ notificationsCount }}
                                    </span>
                                    <span class="sr-only">Notifications</span>
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-72">
                                <div
                                    class="border-b px-3 py-2 text-sm font-semibold"
                                >
                                    Notifications
                                </div>
                                <div
                                    v-if="notificationsCount === 0"
                                    class="px-3 py-5 text-sm text-muted-foreground"
                                >
                                    Aucune notification.
                                </div>
                                <div v-else class="px-3 py-5 text-sm">
                                    Vous avez {{ notificationsCount }}
                                    notification(s).
                                </div>
                            </DropdownMenuContent>
                        </DropdownMenu>

                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="ghost"
                                    class="hidden h-9 max-w-[190px] gap-2 px-2 text-sm font-medium lg:inline-flex"
                                >
                                    <span class="truncate">
                                        {{ userDisplayName }}
                                    </span>
                                    <ChevronDown
                                        class="h-4 w-4 text-muted-foreground"
                                    />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-56">
                                <DropdownMenuLabel class="truncate">
                                    {{ userDisplayName }}
                                </DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    class="cursor-pointer"
                                    @click="openProfile"
                                >
                                    <User class="mr-2 h-4 w-4" />
                                    Profil
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    class="cursor-pointer"
                                    @click="handleLogout"
                                >
                                    <LogOut class="mr-2 h-4 w-4" />
                                    Deconnexion
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>

                        <Button
                            variant="ghost"
                            size="icon"
                            class="h-9 w-9 lg:hidden"
                            @click="toggleMobileMenu"
                        >
                            <X v-if="isMobileMenuOpen" class="h-5 w-5" />
                            <Menu v-else class="h-5 w-5" />
                            <span class="sr-only">Menu</span>
                        </Button>
                    </div>
                </div>

                <div
                    v-if="isMobileMenuOpen"
                    class="border-t border-border py-2 lg:hidden"
                >
                    <nav class="flex flex-col gap-1">
                        <Link
                            v-for="item in navItems"
                            :key="`mobile-${item.href}`"
                            :href="item.href"
                            class="rounded-md px-3 py-2 text-sm font-medium transition-colors"
                            :class="
                                isActive(item.href)
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:bg-secondary hover:text-foreground'
                            "
                            @click="closeMobileMenu"
                        >
                            {{ item.label }}
                        </Link>
                    </nav>

                    <div class="mt-2 border-t border-border pt-2">
                        <p
                            class="px-3 pb-2 text-xs font-medium text-muted-foreground"
                        >
                            {{ userDisplayName }}
                        </p>
                        <div class="flex flex-col gap-1">
                            <Button
                                variant="ghost"
                                class="justify-start gap-2 px-3"
                                @click="openProfileFromMobile"
                            >
                                <User class="h-4 w-4" />
                                Profil
                            </Button>
                            <Button
                                variant="ghost"
                                class="justify-start gap-2 px-3 text-destructive hover:text-destructive"
                                @click="handleLogoutFromMobile"
                            >
                                <LogOut class="h-4 w-4" />
                                Deconnexion
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="mx-auto w-full max-w-5xl flex-1 px-4 py-8">
            <slot />
        </main>
    </div>
</template>
