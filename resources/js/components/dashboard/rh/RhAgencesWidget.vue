<script setup lang="ts">
import type { EffectifAgenceRh } from '@/types/dashboard-rh';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import { CARTE_APOLLO, TITRE_GRAPHIQUE_APOLLO } from '../commissions/apollo';
import { nombreTexte } from './nombres';

// Effectif par agence actuelle du salarié, du plus gros au plus petit (serveur) : présents à ce
// jour (actifs, suspendus), entrées et sorties de la période. Les salariés sans agence ont leur
// propre ligne (vision de toutes les agences seulement).
defineProps<{
    lignes: EffectifAgenceRh[];
}>();

const COLONNES: { champ: keyof EffectifAgenceRh; titre: string }[] = [
    { champ: 'actifs', titre: 'Actifs' },
    { champ: 'suspendus', titre: 'Suspendus' },
    { champ: 'entrees', titre: 'Entrées' },
    { champ: 'sorties', titre: 'Sorties' },
];
</script>

<template>
    <div :class="CARTE_APOLLO">
        <div :class="TITRE_GRAPHIQUE_APOLLO" class="mb-1">
            Effectif par agence
        </div>
        <p class="mb-5 text-[13px] text-[var(--p-text-muted-color)]">
            Agence actuelle du salarié. Actifs et suspendus à ce jour ; entrées
            et sorties sur la période.
        </p>
        <DataTable
            :value="lignes"
            :data-key="(l: EffectifAgenceRh) => l.site_id ?? 'sans-agence'"
            size="small"
            scrollable
            data-testid="rh-agences"
        >
            <Column field="site" header="Agence" frozen />
            <Column
                v-for="c in COLONNES"
                :key="c.champ"
                :header="c.titre"
                header-class="justify-end text-right"
                body-class="text-right tabular-nums whitespace-nowrap"
            >
                <template #body="{ data }">
                    {{ nombreTexte(Number(data[c.champ])) }}
                </template>
            </Column>
            <template #empty>
                <div class="py-6 text-center text-[var(--p-text-muted-color)]">
                    Aucun salarié
                </div>
            </template>
        </DataTable>
    </div>
</template>
