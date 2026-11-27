<script setup lang="ts">
import type { LigneSyntheseStock } from '@/types/dashboard-stock';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import { CARTE_APOLLO, TITRE_GRAPHIQUE_APOLLO } from '../commissions/apollo';
import { quantiteTexte } from './quantites';

// Synthèse de tous les articles, côte à côte : état actuel (physique, réservé, disponible, en
// transit) et flux de la période (entrées, sorties, pertes et casses, écarts). Volontairement sans
// ligne de total : les unités diffèrent d'un article à l'autre (pack 350 ml, pack 1,5 L, sachets…).
defineProps<{
    lignes: LigneSyntheseStock[];
    /** Article choisi, mis en évidence. */
    article: string | null;
}>();

const COLONNES: { champ: keyof LigneSyntheseStock; titre: string }[] = [
    { champ: 'physique', titre: 'Physique' },
    { champ: 'reservee', titre: 'Réservé' },
    { champ: 'disponible', titre: 'Disponible' },
    { champ: 'en_transit', titre: 'En transit' },
    { champ: 'entrees', titre: 'Entrées' },
    { champ: 'sorties', titre: 'Sorties' },
    { champ: 'pertes_casses', titre: 'Pertes et casses' },
    { champ: 'ecarts_manquants', titre: 'Écarts' },
];
</script>

<template>
    <div :class="CARTE_APOLLO">
        <div :class="TITRE_GRAPHIQUE_APOLLO" class="mb-1">
            Synthèse par article
        </div>
        <p class="mb-5 text-[13px] text-[var(--p-text-muted-color)]">
            Quantités en unités de chaque article : jamais additionnées entre
            articles. Entrées, sorties, pertes et écarts sur la période.
        </p>
        <DataTable
            :value="lignes"
            data-key="id"
            size="small"
            scrollable
            :row-class="
                (l: LigneSyntheseStock) =>
                    l.id === article ? 'font-semibold' : ''
            "
            data-testid="stock-synthese"
        >
            <Column field="label" header="Article" frozen />
            <Column
                v-for="c in COLONNES"
                :key="c.champ"
                :header="c.titre"
                header-class="justify-end text-right"
                body-class="text-right tabular-nums whitespace-nowrap"
            >
                <template #body="{ data }">
                    {{ quantiteTexte(Number(data[c.champ])) }}
                </template>
            </Column>
            <template #empty>
                <div class="py-6 text-center text-[var(--p-text-muted-color)]">
                    Aucun article géré en stock
                </div>
            </template>
        </DataTable>
    </div>
</template>
