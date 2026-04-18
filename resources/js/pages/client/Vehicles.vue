<script setup lang="ts">
import ClientLayout from '@/layouts/ClientLayout.vue';
import type { ActorPayload, VehiculeOption } from '@/types/client-space';
import { Head } from '@inertiajs/vue3';

defineProps<{
    actor: ActorPayload;
    owner_vehicules: VehiculeOption[];
}>();
</script>

<template>
    <ClientLayout>
        <Head title="Mon espace - Vehicules" />

        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-semibold">Mes vehicules</h1>
                <p class="mt-1 text-muted-foreground">
                    Liste des vehicules rattaches a votre profil proprietaire.
                </p>
            </div>

            <div class="rounded-xl border border-border bg-card p-5">
                <p class="text-sm text-muted-foreground">
                    Total vehicules proprietaire: {{ owner_vehicules.length }}
                </p>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{
                        actor.organization_name
                            ? `Organisation: ${actor.organization_name}`
                            : 'Organisation non rattachee'
                    }}
                </p>
            </div>

            <div
                v-if="!actor.proprietaire_id"
                class="rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-700 dark:text-amber-300"
            >
                Votre compte n'est pas lie a un profil proprietaire.
            </div>

            <div v-else class="rounded-xl border border-border bg-card p-5">
                <div
                    v-if="owner_vehicules.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Aucun vehicule proprietaire trouve pour le moment.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr
                                class="border-b border-border text-left text-muted-foreground"
                            >
                                <th class="py-2 pr-4 font-medium">
                                    Nom vehicule
                                </th>
                                <th class="py-2 pr-4 font-medium">
                                    Immatriculation
                                </th>
                                <th class="py-2 pr-4 font-medium">Type</th>
                                <th class="py-2 pr-0 font-medium">
                                    Capacite (packs)
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="vehicule in owner_vehicules"
                                :key="vehicule.id"
                                class="border-b border-border/70"
                            >
                                <td class="py-2 pr-4">
                                    {{ vehicule.nom_vehicule }}
                                </td>
                                <td class="py-2 pr-4">
                                    {{ vehicule.immatriculation ?? '-' }}
                                </td>
                                <td class="py-2 pr-4">
                                    {{ vehicule.type_label }}
                                </td>
                                <td class="py-2 pr-0">
                                    {{ vehicule.capacite_packs ?? '-' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </ClientLayout>
</template>
