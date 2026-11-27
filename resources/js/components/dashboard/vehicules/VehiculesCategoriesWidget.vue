<script setup lang="ts">
import { formatPourcentage } from '@/lib/pourcentages';
import type { CategorieVehicules } from '@/types/dashboard-vehicules';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import { CARTE_APOLLO, TITRE_GRAPHIQUE_APOLLO } from '../commissions/apollo';
import { nombreTexte, ouTiret, pluriel } from './nombres';

// Une ligne par catégorie de produit (sachets, bouteilles…) : les quantités ne s'additionnent
// jamais d'une catégorie à l'autre, aucune ligne de total. Capacité = parc actif à ce jour ;
// chargé, remplissage, retours et écarts = rotations de la période (tiret sans accès à leur source).
defineProps<{
    lignes: CategorieVehicules[];
}>();

const pourcentage = (valeur: number | null) =>
    valeur === null ? '—' : formatPourcentage(valeur);
</script>

<template>
    <div :class="CARTE_APOLLO">
        <div :class="TITRE_GRAPHIQUE_APOLLO" class="mb-1">
            Chargement par catégorie
        </div>
        <p class="mb-5 text-[13px] text-[var(--p-text-muted-color)]">
            Quantités dans l’unité de chaque catégorie, jamais additionnées
            entre catégories. Remplissage : chargé ÷ capacité actuelle du
            véhicule, sur les seuls chargements d’un véhicule plafonné pour la
            catégorie.
        </p>
        <DataTable
            :value="lignes"
            :data-key="(l: CategorieVehicules) => l.categorie_id ?? 'sans'"
            size="small"
            scrollable
            data-testid="vehicules-categories"
        >
            <Column field="categorie" header="Catégorie" frozen />
            <Column
                header="Capacité du parc"
                header-class="justify-end text-right"
                body-class="text-right tabular-nums whitespace-nowrap"
            >
                <template #body="{ data }">
                    {{ nombreTexte(data.capacite_parc) }}
                    <span class="text-[var(--p-text-muted-color)]"
                        >·
                        {{
                            pluriel(data.vehicules_plafonnes, 'véhicule')
                        }}</span
                    >
                </template>
            </Column>
            <Column
                header="Chargé"
                header-class="justify-end text-right"
                body-class="text-right tabular-nums whitespace-nowrap"
            >
                <template #body="{ data }">{{ ouTiret(data.charge) }}</template>
            </Column>
            <Column
                header="Remplissage"
                header-class="justify-end text-right"
                body-class="text-right tabular-nums whitespace-nowrap"
            >
                <template #body="{ data }">{{
                    pourcentage(data.remplissage)
                }}</template>
            </Column>
            <Column
                header="Retourné"
                header-class="justify-end text-right"
                body-class="text-right tabular-nums whitespace-nowrap"
            >
                <template #body="{ data }">
                    {{ ouTiret(data.retourne) }}
                    <span
                        v-if="data.taux_retour !== null"
                        class="text-[var(--p-text-muted-color)]"
                        >· {{ formatPourcentage(data.taux_retour) }}</span
                    >
                </template>
            </Column>
            <Column
                header="Écarts de réception"
                header-class="justify-end text-right"
                body-class="text-right tabular-nums whitespace-nowrap"
            >
                <template #body="{ data }">
                    <template v-if="data.ecarts_manquants === null">—</template>
                    <template
                        v-else-if="
                            data.ecarts_manquants === 0 &&
                            data.ecarts_surplus === 0
                        "
                        >0</template
                    >
                    <template v-else>
                        <span
                            :class="{
                                'text-amber-600 dark:text-amber-400':
                                    data.ecarts_manquants > 0,
                            }"
                            >−{{ nombreTexte(data.ecarts_manquants) }}</span
                        >
                        /
                        <span>+{{ nombreTexte(data.ecarts_surplus) }}</span>
                    </template>
                </template>
            </Column>
            <template #empty>
                <div class="py-6 text-center text-[var(--p-text-muted-color)]">
                    Aucune capacité configurée ni aucun chargement
                </div>
            </template>
        </DataTable>
    </div>
</template>
