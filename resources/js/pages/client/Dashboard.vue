<script setup lang="ts">
import ClientLayout from '@/layouts/ClientLayout.vue';
import type { EarningsPayload, VehiculeOption } from '@/types/client-space';
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{
    earnings: EarningsPayload;
    vehicules: VehiculeOption[];
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);

function formatMoney(value: number): string {
    return `${new Intl.NumberFormat('fr-FR').format(value ?? 0)} GNF`;
}
</script>

<template>
    <ClientLayout>
        <Head title="Mon espace - Accueil" />

        <div class="space-y-8">
            <div>
                <h1 class="text-2xl font-semibold">
                    Bonjour, {{ user.prenom }}
                </h1>
                <p class="mt-1 text-muted-foreground">
                    Bienvenue dans votre espace partenaire.
                </p>
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-border bg-card p-5">
                    <p class="text-sm text-muted-foreground">Gains cumules</p>
                    <p class="mt-2 text-2xl font-semibold text-foreground">
                        {{ formatMoney(earnings.total_earned) }}
                    </p>
                </div>
                <div class="rounded-xl border border-border bg-card p-5">
                    <p class="text-sm text-muted-foreground">Deja verses</p>
                    <p class="mt-2 text-2xl font-semibold text-foreground">
                        {{ formatMoney(earnings.total_paid) }}
                    </p>
                </div>
                <div class="rounded-xl border border-border bg-card p-5">
                    <p class="text-sm text-muted-foreground">Reste à payer</p>
                    <p class="mt-2 text-2xl font-semibold text-foreground">
                        {{ formatMoney(earnings.balance) }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ earnings.operations_count }} operation(s)
                    </p>
                </div>
            </div>

            <div class="rounded-xl border border-border bg-card p-5">
                <h2 class="text-lg font-semibold">Resume rapide</h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Vehicules rattaches a votre compte: {{ vehicules.length }}.
                </p>
                <p class="mt-1 text-sm text-muted-foreground">
                    Utilisez le menu en haut pour proposer un vehicule,
                    consulter les gains detaillees ou mettre a jour votre
                    profil.
                </p>
            </div>
        </div>
    </ClientLayout>
</template>
