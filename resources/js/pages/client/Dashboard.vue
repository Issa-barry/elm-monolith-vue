<script setup lang="ts">
import ClientLayout from '@/layouts/ClientLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <ClientLayout>
        <Head title="Mon espace" />

        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-semibold">
                    Bonjour, {{ user.prenom }} 👋
                </h1>
                <p class="mt-1 text-muted-foreground">Bienvenue dans votre espace client.</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="rounded-xl border border-border bg-card p-5">
                    <p class="text-sm text-muted-foreground">Téléphone</p>
                    <p class="mt-1 font-medium">{{ formatPhoneDisplay(user.telephone) }}</p>
                </div>
                <div class="rounded-xl border border-border bg-card p-5">
                    <p class="text-sm text-muted-foreground">Membre depuis</p>
                    <p class="mt-1 font-medium">
                        {{ new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: 'long', year: 'numeric' }).format(new Date(user.created_at)) }}
                    </p>
                </div>
            </div>
        </div>
    </ClientLayout>
</template>
