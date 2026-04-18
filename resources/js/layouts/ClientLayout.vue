<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { home, logout } from '@/routes';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
const currentUrl = computed(() => page.url);

const navItems = [
    { label: 'Accueil', href: '/client/dashboard' },
    { label: 'Vehicules', href: '/client/vehicules' },
    { label: 'Proposer vehicule', href: '/client/proposer-vehicule' },
    { label: 'Gains', href: '/client/gains' },
    { label: 'Profil', href: '/client/profile' },
];

function isActive(href: string): boolean {
    return currentUrl.value === href || currentUrl.value.startsWith(`${href}?`);
}
</script>

<template>
    <div class="flex min-h-screen flex-col bg-background">
        <header
            class="sticky top-0 z-50 border-b border-border bg-background/95 backdrop-blur"
        >
            <div
                class="mx-auto flex h-14 max-w-5xl items-center justify-between px-4"
            >
                <Link
                    :href="home()"
                    class="flex items-center gap-2 transition-opacity hover:opacity-80"
                >
                    <AppLogoIcon class="h-7 w-7 fill-current text-primary" />
                    <span class="font-semibold">Eau la maman</span>
                </Link>

                <div class="flex items-center gap-3">
                    <span class="text-sm text-muted-foreground">
                        {{ user.prenom }} {{ user.nom }}
                    </span>
                    <Button variant="outline" size="sm" :as-child="true">
                        <Link :href="logout()" as="button">Deconnexion</Link>
                    </Button>
                </div>
            </div>

            <div class="border-t border-border/80">
                <nav class="mx-auto flex max-w-5xl flex-wrap gap-2 px-4 py-2">
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
                </nav>
            </div>
        </header>

        <main class="mx-auto w-full max-w-5xl flex-1 px-4 py-8">
            <slot />
        </main>
    </div>
</template>
