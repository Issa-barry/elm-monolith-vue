<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import type { VehiculeClasse } from '@/types/dashboard-vehicules';
import { Link } from '@inertiajs/vue3';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import { computed } from 'vue';
import {
    CARTE_APOLLO,
    TITRE_GRAPHIQUE_APOLLO,
    montantGnf,
} from '../commissions/apollo';
import { nombreTexte, ouTiret } from './nombres';

// Un véhicule par ligne, les plus actifs d'abord (serveur) : ceux du parc, et ceux d'une autre
// agence qui ont une activité ou une dépense comptée dans les agences du filtre. Tri par colonne
// sur les données reçues ; les colonnes d'une source non consultable sont absentes.
const props = defineProps<{
    lignes: VehiculeClasse[];
    activite: boolean;
    depenses: boolean;
}>();

const vide = computed(() => props.lignes.length === 0);
</script>

<template>
    <div :class="CARTE_APOLLO">
        <div :class="TITRE_GRAPHIQUE_APOLLO" class="mb-1">Véhicules</div>
        <p class="mb-5 text-[13px] text-[var(--p-text-muted-color)]">
            État à ce jour ; rotations, jours d’activité, retours et dépenses
            sur la période.
        </p>
        <DataTable
            :value="lignes"
            data-key="id"
            size="small"
            scrollable
            paginator
            :rows="10"
            :always-show-paginator="false"
            removable-sort
            data-testid="vehicules-classement"
        >
            <Column field="nom" header="Véhicule" sortable frozen>
                <template #body="{ data }">
                    <span v-if="data.supprime">{{ data.nom }}</span>
                    <Link
                        v-else
                        :href="`/backoffice/vehicules/${data.id}`"
                        class="text-[var(--p-primary-color)] hover:underline"
                        data-testid="vehicule-fiche"
                        >{{ data.nom }}</Link
                    >
                    <span
                        v-if="data.immatriculation"
                        class="ml-1 text-[var(--p-text-muted-color)]"
                        >· {{ data.immatriculation }}</span
                    >
                </template>
            </Column>
            <Column field="site" header="Agence actuelle" sortable />
            <Column field="type" header="Type" sortable />
            <Column field="statut_label" header="État" sortable>
                <template #body="{ data }">
                    <StatusDot
                        :status="data.statut"
                        :label="data.statut_label"
                    />
                </template>
            </Column>
            <Column
                v-if="activite"
                field="rotations"
                header="Rotations"
                sortable
                header-class="justify-end text-right"
                body-class="text-right tabular-nums"
            >
                <template #body="{ data }">{{
                    ouTiret(data.rotations)
                }}</template>
            </Column>
            <Column
                v-if="activite"
                field="jours"
                header="Jours d’activité"
                sortable
                header-class="justify-end text-right"
                body-class="text-right tabular-nums"
            >
                <template #body="{ data }">{{ ouTiret(data.jours) }}</template>
            </Column>
            <Column
                v-if="activite"
                field="avec_retour"
                header="Avec retour"
                sortable
                header-class="justify-end text-right"
                body-class="text-right tabular-nums"
            >
                <template #body="{ data }">{{
                    ouTiret(data.avec_retour)
                }}</template>
            </Column>
            <Column
                v-if="depenses"
                field="depenses"
                header="Dépenses validées"
                sortable
                header-class="justify-end text-right"
                body-class="text-right tabular-nums whitespace-nowrap"
            >
                <template #body="{ data }">{{
                    data.depenses === null ? '—' : montantGnf(data.depenses)
                }}</template>
            </Column>
            <template #empty>
                <div class="py-6 text-center text-[var(--p-text-muted-color)]">
                    Aucun véhicule dans ce périmètre
                </div>
            </template>
        </DataTable>
        <p
            v-if="!vide"
            class="mt-3 text-[13px] text-[var(--p-text-muted-color)]"
        >
            {{ nombreTexte(lignes.length) }} véhicule{{
                lignes.length > 1 ? 's' : ''
            }}
        </p>
    </div>
</template>
