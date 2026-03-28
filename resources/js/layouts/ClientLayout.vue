<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { Link, usePage } from '@inertiajs/vue3';
import { home, logout } from '@/routes';
import { computed } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <div class="flex min-h-screen flex-col bg-background">
        <!-- Header -->
        <header class="sticky top-0 z-50 border-b border-border bg-background/95 backdrop-blur">
            <div class="mx-auto flex h-14 max-w-5xl items-center justify-between px-4">
                <Link :href="home()" class="flex items-center gap-2 hover:opacity-80 transition-opacity">
                    <AppLogoIcon class="h-7 w-7 fill-current text-foreground" />
                    <span class="font-semibold">Eau la maman</span>
                </Link>

                <div class="flex items-center gap-3">
                    <span class="text-sm text-muted-foreground">
                        {{ user.prenom }} {{ user.nom }}
                    </span>
                    <Button variant="outline" size="sm" :as-child="true">
                        <Link :href="logout()" as="button">
                            Déconnexion
                        </Link>
                    </Button>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="mx-auto w-full max-w-5xl flex-1 px-4 py-8">
            <slot />
        </main>
    </div>
</template>
