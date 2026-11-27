<script setup lang="ts">
import type {
    CompteurAlertesStock,
    EtatStock,
    FluxPeriodeStock,
} from '@/types/dashboard-stock';
import { computed } from 'vue';
import { CARTE_APOLLO } from '../commissions/apollo';
import { quantiteTexte } from './quantites';

// Cartes de Stat-Stock, mêmes cartes Apollo que les autres tableaux de bord : quatre par ligne sur
// grand écran (`xl:col-span-3`), deux sur tablette, une sur téléphone.
// Ligne 1 (état actuel, à ce jour) : disponible, réservé, en transit pour l'article ; alertes pour
//   tous les articles (un nombre de couples article × site, seul total possible).
// Ligne 2 (période) : entrées, sorties, pertes et casses, écarts de réception de l'article.
// Les quantités sont des unités de l'article : jamais additionnées entre articles.
const props = defineProps<{
    etat: EtatStock;
    alertes: CompteurAlertesStock;
    flux: FluxPeriodeStock;
    /** Libellé de l'article choisi, rappelé sous les quantités. */
    article: string | null;
}>();

interface Carte {
    id: string;
    titre: string;
    definition: string;
    valeur: string;
    detail: string;
}

const pluriel = (n: number, mot: string) => `${n} ${mot}${n > 1 ? 's' : ''}`;

const etatActuel = computed((): Carte[] => [
    {
        id: 'disponible',
        titre: 'Stock disponible',
        definition:
            'Physique moins réservé : ce qui reste vendable aujourd’hui sur les agences, pour l’article choisi.',
        valeur: quantiteTexte(props.etat.disponible),
        detail: `Physique ${quantiteTexte(props.etat.physique)} − réservé ${quantiteTexte(props.etat.reservee)}`,
    },
    {
        id: 'reserve',
        titre: 'Stock réservé',
        definition:
            'Engagé pour des commandes confirmées, pas encore chargées : toujours dans le stock physique, plus vendable.',
        valeur: quantiteTexte(props.etat.reservee),
        detail: 'Engagé pour les commandes',
    },
    {
        id: 'transit',
        titre: 'En transit',
        definition:
            'Quantités chargées des transferts en route vers les agences : sorties du site source, pas encore reçues.',
        valeur: quantiteTexte(props.etat.en_transit),
        detail: `${pluriel(props.etat.transferts_en_transit, 'transfert')} en cours`,
    },
    {
        id: 'alertes',
        titre: 'Alertes stock',
        definition:
            'Couples article × agence avec alerte active, en rupture, stock faible ou stock négatif — tous articles.',
        valeur: quantiteTexte(props.alertes.total),
        detail: `${pluriel(props.alertes.ruptures, 'rupture')} · ${props.alertes.faibles} ${props.alertes.faibles > 1 ? 'stocks faibles' : 'stock faible'} · ${pluriel(props.alertes.negatifs, 'négatif')}`,
    },
]);

const periode = computed((): Carte[] => [
    {
        id: 'entrees',
        titre: 'Entrées',
        definition:
            'Entrées en stock de la période (production, réception, retour, transfert reçu, correction…), hors mouvements annulés.',
        valeur: quantiteTexte(props.flux.entrees),
        detail: 'Sur la période',
    },
    {
        id: 'sorties',
        titre: 'Sorties',
        definition:
            'Sorties de stock de la période (ventes, transferts envoyés, pertes, casses…), hors mouvements annulés.',
        valeur: quantiteTexte(props.flux.sorties),
        detail: 'Sur la période',
    },
    {
        id: 'pertes',
        titre: 'Pertes et casses',
        definition: 'Sorties manuelles au motif Perte ou Casse sur la période.',
        valeur: quantiteTexte(props.flux.pertes_casses),
        detail: 'Ajustements Perte et Casse',
    },
    {
        id: 'ecarts',
        titre: 'Écarts de réception',
        definition:
            'Quantités chargées mais non reçues sur les transferts reçus pendant la période ; jamais remises en stock.',
        valeur: quantiteTexte(props.flux.ecarts_manquants),
        detail: `Manquants · surplus ${quantiteTexte(props.flux.ecarts_surplus)}`,
    },
]);

const lignes = computed(() => [
    {
        id: 'etat',
        titre: 'État actuel',
        precision: props.article ?? '—',
        cartes: etatActuel.value,
    },
    {
        id: 'periode',
        titre: 'Sur la période',
        precision: props.article ?? '—',
        cartes: periode.value,
    },
]);
</script>

<template>
    <template v-for="ligne in lignes" :key="ligne.id">
        <h2
            class="col-span-12 -mb-4 text-sm font-semibold text-[var(--p-text-muted-color)]"
            :data-testid="`stock-ligne-${ligne.id}`"
        >
            {{ ligne.titre }}
            <span class="font-normal">· {{ ligne.precision }}</span>
        </h2>
        <div
            v-for="carte in ligne.cartes"
            :key="carte.id"
            class="col-span-12 md:col-span-6 xl:col-span-3"
            :data-testid="`kpi-${carte.id}`"
        >
            <div :class="CARTE_APOLLO">
                <span
                    class="text-[15.75px] leading-[24.5px] font-semibold"
                    :title="carte.definition"
                    >{{ carte.titre }}</span
                >
                <div
                    class="mt-3.5 text-[31.5px] leading-[35px] font-bold whitespace-nowrap text-[var(--p-surface-900)] dark:text-[var(--p-surface-0)]"
                    data-slot="valeur"
                >
                    {{ carte.valeur }}
                </div>
                <div
                    class="mt-1 font-medium text-[var(--p-text-muted-color)]"
                    data-slot="detail"
                >
                    {{ carte.detail }}
                </div>
            </div>
        </div>
    </template>
</template>
