<script setup lang="ts">
import type {
    CompteurAlertesStock,
    EtatStock,
    FluxPeriodeStock,
} from '@/types/dashboard-stock';
import { computed } from 'vue';
import type { CarteIndicateur, LigneIndicateurs } from '../shared/indicateurs';
import LignesIndicateursApollo from '../shared/LignesIndicateursApollo.vue';
import { quantiteTexte } from './quantites';

// Cartes de Stat-Stock, cartes Apollo partagées avec Stat-RH (LignesIndicateursApollo) : quatre par
// ligne sur grand écran, deux sur tablette, une sur téléphone.
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

const pluriel = (n: number, mot: string) => `${n} ${mot}${n > 1 ? 's' : ''}`;

const etatActuel = computed((): CarteIndicateur[] => [
    {
        id: 'disponible',
        titre: 'Stock disponible',
        icone: 'pi-box',
        definition:
            'Physique moins réservé : ce qui reste vendable aujourd’hui sur les agences, pour l’article choisi.',
        valeur: quantiteTexte(props.etat.disponible),
        detail: `Physique ${quantiteTexte(props.etat.physique)} − réservé ${quantiteTexte(props.etat.reservee)}`,
    },
    {
        id: 'reserve',
        titre: 'Stock réservé',
        icone: 'pi-lock',
        definition:
            'Engagé pour des commandes confirmées, pas encore chargées : toujours dans le stock physique, plus vendable.',
        valeur: quantiteTexte(props.etat.reservee),
        detail: 'Engagé pour les commandes',
    },
    {
        id: 'transit',
        titre: 'En transit',
        icone: 'pi-truck',
        definition:
            'Quantités chargées des transferts en route vers les agences : sorties du site source, pas encore reçues.',
        valeur: quantiteTexte(props.etat.en_transit),
        detail: `${pluriel(props.etat.transferts_en_transit, 'transfert')} en cours`,
    },
    {
        id: 'alertes',
        titre: 'Alertes stock',
        icone: 'pi-exclamation-triangle',
        definition:
            'Couples article × agence avec alerte active, en rupture, stock faible ou stock négatif — tous articles.',
        valeur: quantiteTexte(props.alertes.total),
        ton: props.alertes.total > 0 ? 'avertissement' : 'neutre',
        detail: `${pluriel(props.alertes.ruptures, 'rupture')} · ${pluriel(props.alertes.faibles, 'faible')} · ${pluriel(props.alertes.negatifs, 'négatif')}`,
    },
]);

const periode = computed((): CarteIndicateur[] => [
    {
        id: 'entrees',
        titre: 'Entrées',
        icone: 'pi-arrow-down-left',
        definition:
            'Entrées en stock de la période (production, réception, retour, transfert reçu, correction…), hors mouvements annulés.',
        valeur: quantiteTexte(props.flux.entrees),
        detail: 'Sur la période',
    },
    {
        id: 'sorties',
        titre: 'Sorties',
        icone: 'pi-arrow-up-right',
        definition:
            'Sorties de stock de la période (ventes, transferts envoyés, pertes, casses…), hors mouvements annulés.',
        valeur: quantiteTexte(props.flux.sorties),
        detail: 'Sur la période',
    },
    {
        id: 'pertes',
        titre: 'Pertes et casses',
        icone: 'pi-trash',
        definition: 'Sorties manuelles au motif Perte ou Casse sur la période.',
        valeur: quantiteTexte(props.flux.pertes_casses),
        detail: 'Ajustements Perte et Casse',
    },
    {
        id: 'ecarts',
        titre: 'Écarts de réception',
        icone: 'pi-arrows-h',
        definition:
            'Quantités chargées mais non reçues sur les transferts reçus pendant la période ; jamais remises en stock.',
        valeur: quantiteTexte(props.flux.ecarts_manquants),
        detail: `Manquants · surplus ${quantiteTexte(props.flux.ecarts_surplus)}`,
    },
]);

const lignes = computed((): LigneIndicateurs[] => [
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
    <LignesIndicateursApollo :lignes="lignes" prefixe="stock" />
</template>
