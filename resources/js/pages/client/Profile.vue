<script setup lang="ts">
import ClientLayout from '@/layouts/ClientLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import type { ActorPayload } from '@/types/client-space';
import { Head } from '@inertiajs/vue3';

defineProps<{
    actor: ActorPayload;
    profile: {
        full_name: string;
        telephone: string | null;
        email: string | null;
        member_since_label: string | null;
        roles: string[];
        vehicules_count: number;
        operations_count: number;
    };
}>();
</script>

<template>
    <ClientLayout>
        <Head title="Mon espace - Profil" />

        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-semibold">Mon profil</h1>
                <p class="mt-1 text-muted-foreground">
                    Informations de compte et statut partenaire.
                </p>
            </div>

            <div class="rounded-xl border border-border bg-card p-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <p class="text-sm text-muted-foreground">Nom complet</p>
                        <p class="mt-1 font-medium text-foreground">
                            {{ profile.full_name }}
                        </p>
                    </div>
                    <div>
                        <p class="text-sm text-muted-foreground">
                            Membre depuis
                        </p>
                        <p class="mt-1 font-medium text-foreground">
                            {{ profile.member_since_label ?? '-' }}
                        </p>
                    </div>
                    <div>
                        <p class="text-sm text-muted-foreground">Telephone</p>
                        <p class="mt-1 font-medium text-foreground">
                            {{ formatPhoneDisplay(profile.telephone) }}
                        </p>
                    </div>
                    <div>
                        <p class="text-sm text-muted-foreground">Email</p>
                        <p class="mt-1 font-medium text-foreground">
                            {{ profile.email ?? '-' }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-border bg-card p-5">
                <h2 class="text-lg font-semibold">Roles detectes</h2>
                <div class="mt-3 flex flex-wrap gap-2">
                    <span
                        v-for="role in profile.roles"
                        :key="role"
                        class="rounded-full bg-secondary px-3 py-1 text-xs font-medium text-foreground"
                    >
                        {{ role }}
                    </span>
                    <span
                        v-if="profile.roles.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        Aucun role disponible.
                    </span>
                </div>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <p class="text-sm text-muted-foreground">
                        Vehicules partenaires: {{ profile.vehicules_count }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Operations enregistrees: {{ profile.operations_count }}
                    </p>
                </div>
            </div>

            <div class="rounded-xl border border-border bg-card p-5">
                <h2 class="text-lg font-semibold">Statut partenaire</h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{
                        actor.is_partner
                            ? 'Votre compte est actuellement reconnu comme partenaire.'
                            : "Votre compte n'est pas encore partenaire."
                    }}
                </p>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{
                        actor.organization_name
                            ? `Organisation: ${actor.organization_name}`
                            : 'Organisation non rattachee'
                    }}
                </p>
            </div>
        </div>
    </ClientLayout>
</template>
