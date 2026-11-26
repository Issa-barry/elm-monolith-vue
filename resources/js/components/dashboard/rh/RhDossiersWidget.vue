<script setup lang="ts">
import type { ACompleterRh } from '@/types/dashboard-rh';
import { computed } from 'vue';
import { CARTE_APOLLO, TITRE_GRAPHIQUE_APOLLO } from '../commissions/apollo';
import { nombreTexte } from './nombres';

// Dossiers auxquels il manque une donnée utile aux indicateurs, pour les salariés présents (actifs et
// suspendus) : sans date d'embauche, le salarié n'est compté ni en entrée ni dans le taux
// d'absentéisme. S'y ajoutent les anciennes fiches « Sorti » sans date de sortie, elles aussi
// exclues du taux.
const props = defineProps<{
    dossiers: ACompleterRh;
}>();

const lignes = computed(() => [
    {
        id: 'date-embauche',
        libelle: 'Sans date d’embauche',
        valeur: props.dossiers.sans_date_embauche,
    },
    {
        id: 'fonction',
        libelle: 'Sans fonction',
        valeur: props.dossiers.sans_fonction,
    },
    {
        id: 'service',
        libelle: 'Sans service',
        valeur: props.dossiers.sans_service,
    },
    {
        id: 'agence',
        libelle: 'Sans agence',
        valeur: props.dossiers.sans_agence,
    },
    {
        id: 'sortis-sans-date',
        libelle: 'Sortis sans date de sortie',
        valeur: props.dossiers.sortis_sans_date,
    },
]);
</script>

<template>
    <div :class="CARTE_APOLLO">
        <div :class="TITRE_GRAPHIQUE_APOLLO" class="mb-1">
            Dossiers à compléter
        </div>
        <p class="mb-5 text-[13px] text-[var(--p-text-muted-color)]">
            Sur {{ nombreTexte(dossiers.presents) }} salariés présents. À
            compléter dans la fiche salarié ou par l’import RH : un salarié sans
            date d’embauche est exclu du taux d’absentéisme.
        </p>
        <ul class="divide-y divide-[var(--p-content-border-color)]">
            <li
                v-for="ligne in lignes"
                :key="ligne.id"
                class="flex items-center justify-between py-2.5"
                :data-testid="`rh-dossiers-${ligne.id}`"
            >
                <span>{{ ligne.libelle }}</span>
                <span
                    class="font-semibold tabular-nums"
                    :class="
                        ligne.valeur > 0
                            ? 'text-amber-600 dark:text-amber-400'
                            : 'text-[var(--p-text-muted-color)]'
                    "
                    >{{ nombreTexte(ligne.valeur) }}</span
                >
            </li>
        </ul>
    </div>
</template>
