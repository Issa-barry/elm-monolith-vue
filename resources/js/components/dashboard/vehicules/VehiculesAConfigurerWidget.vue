<script setup lang="ts">
import type { VehiculeAConfigurer } from '@/types/dashboard-vehicules';
import { Link } from '@inertiajs/vue3';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import { CARTE_APOLLO, TITRE_GRAPHIQUE_APOLLO } from '../commissions/apollo';

// Véhicules actifs du parc dont au moins une opération est refusée par le serveur, avec ses motifs
// (mêmes messages que les formulaires). En orange (standard A8) : le véhicule reste utilisable pour
// ses autres opérations, rien n'est bloqué au-delà de ce qui est indiqué.
defineProps<{
    lignes: VehiculeAConfigurer[];
}>();
</script>

<template>
    <div :class="CARTE_APOLLO">
        <div :class="TITRE_GRAPHIQUE_APOLLO" class="mb-1">
            Véhicules à configurer
        </div>
        <p class="mb-5 text-[13px] text-[var(--p-text-muted-color)]">
            Véhicules actifs dont une opération est refusée : à corriger sur la
            fiche du véhicule, de son équipe ou de ses livreurs.
        </p>
        <DataTable
            :value="lignes"
            data-key="id"
            size="small"
            scrollable
            paginator
            :rows="10"
            :always-show-paginator="false"
            data-testid="vehicules-a-configurer"
        >
            <Column header="Véhicule">
                <template #body="{ data }">
                    <Link
                        :href="`/backoffice/vehicules/${data.id}`"
                        class="text-[var(--p-primary-color)] hover:underline"
                        >{{ data.nom }}</Link
                    >
                    <span
                        v-if="data.immatriculation"
                        class="ml-1 text-[var(--p-text-muted-color)]"
                        >· {{ data.immatriculation }}</span
                    >
                </template>
            </Column>
            <Column field="site" header="Agence" />
            <Column field="usages" header="Usages" />
            <Column header="Motifs" :style="{ minWidth: '18rem' }">
                <template #body="{ data }">
                    <ul class="space-y-1 text-amber-600 dark:text-amber-400">
                        <li v-for="(motif, i) in data.motifs" :key="i">
                            {{ motif }}
                        </li>
                    </ul>
                </template>
            </Column>
            <template #empty>
                <div class="py-6 text-center text-[var(--p-text-muted-color)]">
                    Tous les véhicules actifs sont opérationnels
                </div>
            </template>
        </DataTable>
    </div>
</template>
