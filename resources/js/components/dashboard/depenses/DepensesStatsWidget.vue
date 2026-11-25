<script setup lang="ts">
import { formatPourcentage } from '@/lib/pourcentages';
import type {
    CleIndicateurDepenses,
    EnAttenteDepenses,
    IndicateursDepenses,
    RejeteesDepenses,
    TrancheDepenses,
} from '@/types/dashboard-depenses';
import Knob from 'primevue/knob';
import { computed } from 'vue';
import {
    CARTE_APOLLO,
    evolutionTexte,
    montantCompact,
    montantGnf,
    traceCourbe,
} from '../commissions/apollo';

// Six cartes de Stat-Dépenses, mêmes cartes Apollo que Stat-Commissions (StatsEcommerceWidget) :
// trois par ligne sur grand écran (`xl:col-span-4`), deux sur tablette, une sur téléphone.
// Ligne 1 : Total, Payées, En attente ; ligne 2 : Charges entreprise, Avances, Rejetées.
// Les cartes en montant gardent la mini-courbe d'Apollo (montants réels de chaque tranche) ; « En
// attente » montre l'ancienneté, « Rejetées » le taux de rejet dans le Knob d'Apollo.
// Couleurs d'Apollo, comme les autres tableaux de bord : vert en hausse, rose en baisse (texte et
// mini-courbe), gris sans tendance (décision du 24/11/2026).
const props = defineProps<{
    indicateurs: IndicateursDepenses;
    enAttente: EnAttenteDepenses;
    rejetees: RejeteesDepenses;
    tranches: TrancheDepenses[];
}>();

interface CarteMontant {
    id: CleIndicateurDepenses;
    titre: string;
    definition: string;
}

const CARTES: Record<CleIndicateurDepenses, CarteMontant> = {
    total: {
        id: 'total',
        titre: 'Total des dépenses',
        definition:
            'Dépenses validées + dépenses soumises en attente de validation. Brouillons, rejetées et annulées exclues.',
    },
    payees: {
        id: 'payees',
        titre: 'Dépenses payées',
        definition:
            'Dépenses validées : la validation passe la sortie de trésorerie.',
    },
    charges: {
        id: 'charges',
        titre: 'Charges entreprise',
        definition:
            'Dépenses validées supportées par l’entreprise, hors avances récupérables.',
    },
    avances: {
        id: 'avances',
        titre: 'Avances récupérables',
        definition:
            'Dépenses validées des catégories Véhicule, Propriétaire, Livreur et Parrain, retenues ensuite sur leurs commissions.',
    },
};

// Dimensions de la petite mini-courbe d'Apollo : 115 × 41, trait de 1 px.
const LARGEUR = 115;
const HAUTEUR = 41;

type Tendance = 'hausse' | 'baisse' | 'neutre';

function tendance(evolution: number | null): Tendance {
    if (evolution === null || evolution === 0) return 'neutre';
    return evolution > 0 ? 'hausse' : 'baisse';
}

const COULEUR_TEXTE: Record<Tendance, string> = {
    hausse: 'text-[var(--p-green-500)]',
    baisse: 'text-[var(--p-pink-500)]',
    neutre: 'text-[var(--p-text-muted-color)]',
};

function carte(id: CleIndicateurDepenses) {
    const indicateur = props.indicateurs[id];
    const sens = tendance(indicateur.evolution);

    return {
        ...CARTES[id],
        indicateur,
        sens,
        trace: traceCourbe(
            props.tranches.map((t) => t[id]),
            LARGEUR,
            HAUTEUR,
        ),
        couleurCourbe:
            sens === 'baisse' ? 'var(--p-pink-500)' : 'var(--p-primary-color)',
    };
}

const ligne1 = computed(() => [carte('total'), carte('payees')]);
const ligne2 = computed(() => [carte('charges'), carte('avances')]);

const nombreDepenses = (n: number) => `${n} dépense${n > 1 ? 's' : ''}`;

const anciennete = computed(() => {
    const jours = props.enAttente.anciennete_jours;
    if (jours === null) return null;

    return jours === 0 ? "Aujourd'hui" : `${jours} j`;
});

const plusAncienneLabel = computed(() => {
    const date = props.enAttente.plus_ancienne;
    if (date === null) return '';
    const [annee, mois, jour] = date.split('-');

    return `dépense du ${jour}/${mois}/${annee}`;
});

const knob = computed(() => Math.round(props.rejetees.taux ?? 0));
const knobTexte = computed(() =>
    props.rejetees.taux === null ? '—' : formatPourcentage(props.rejetees.taux),
);

function libelleEvolution(evolution: number | null): string {
    return evolution === null ? '—' : evolutionTexte(evolution);
}

function astuceEvolution(evolution: number | null): string {
    return evolution === null
        ? 'Aucune comparaison possible : rien sur la période de comparaison.'
        : 'Évolution par rapport à la période de comparaison.';
}

const CLASSE_COLONNE = 'col-span-12 md:col-span-6 xl:col-span-4';
const CLASSE_MONTANT =
    'text-[31.5px] leading-[35px] font-bold whitespace-nowrap text-[var(--p-surface-900)] dark:text-[var(--p-surface-0)]';
const CLASSE_DEVISE =
    'ml-1 inline-block text-[14px] font-medium whitespace-nowrap text-[var(--p-text-muted-color)]';
const CLASSE_TITRE = 'text-[15.75px] leading-[24.5px] font-semibold';
</script>

<template>
    <template v-for="(cartes, rang) in [ligne1, ligne2]" :key="rang">
        <div
            v-for="c in cartes"
            :key="c.id"
            :class="CLASSE_COLONNE"
            :data-testid="`kpi-${c.id}`"
        >
            <div :class="CARTE_APOLLO">
                <span :class="CLASSE_TITRE" :title="c.definition">{{
                    c.titre
                }}</span>
                <div class="mt-3.5 flex items-start justify-between">
                    <div class="w-7/12">
                        <span
                            :class="CLASSE_MONTANT"
                            :title="montantGnf(c.indicateur.montant)"
                            data-slot="montant"
                            >{{ montantCompact(c.indicateur.montant) }}</span
                        >
                        <span :class="CLASSE_DEVISE">GNF</span>
                        <div
                            :class="COULEUR_TEXTE[c.sens]"
                            :title="astuceEvolution(c.indicateur.evolution)"
                            data-slot="evolution"
                        >
                            <span class="font-medium">{{
                                libelleEvolution(c.indicateur.evolution)
                            }}</span>
                            <i
                                v-if="c.sens !== 'neutre'"
                                class="ml-[7px] text-[14px]! leading-[14px]!"
                                :class="
                                    c.sens === 'hausse'
                                        ? 'pi pi-arrow-up'
                                        : 'pi pi-arrow-down'
                                "
                            ></i>
                        </div>
                    </div>
                    <div class="w-5/12">
                        <svg
                            width="100%"
                            :viewBox="`0 0 ${LARGEUR} ${HAUTEUR}`"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                            aria-hidden="true"
                        >
                            <path
                                :d="c.trace"
                                :style="{
                                    strokeWidth: '1px',
                                    stroke: c.couleurCourbe,
                                }"
                            />
                        </svg>
                    </div>
                </div>
            </div>
        </div>

        <!-- Fin de la ligne 1 : en attente de validation. -->
        <div
            v-if="rang === 0"
            :class="CLASSE_COLONNE"
            data-testid="kpi-en_attente"
        >
            <div :class="CARTE_APOLLO">
                <span
                    :class="CLASSE_TITRE"
                    title="Dépenses soumises sur la période, pas encore validées. Une demande, pas une dette : elle peut encore être rejetée. Exclues des dépenses payées."
                    >En attente de validation</span
                >
                <div class="mt-3.5 flex items-start justify-between gap-2">
                    <div class="w-7/12">
                        <span
                            :class="CLASSE_MONTANT"
                            :title="montantGnf(enAttente.montant)"
                            data-slot="montant"
                            >{{ montantCompact(enAttente.montant) }}</span
                        >
                        <span :class="CLASSE_DEVISE">GNF</span>
                        <div
                            class="font-medium text-[var(--p-text-muted-color)]"
                            data-slot="nombre"
                        >
                            {{ nombreDepenses(enAttente.nombre) }}
                        </div>
                    </div>
                    <div
                        class="w-5/12 text-right"
                        data-slot="anciennete"
                        title="Ancienneté de la plus ancienne dépense en attente, comptée depuis la date de la dépense (la date de soumission n'est pas conservée)."
                    >
                        <template v-if="anciennete !== null">
                            <div
                                class="text-[12.25px] text-[var(--p-text-muted-color)]"
                            >
                                Plus ancienne
                            </div>
                            <div
                                class="text-[21px] leading-[28px] font-bold text-[var(--p-surface-900)] dark:text-[var(--p-surface-0)]"
                            >
                                {{ anciennete }}
                            </div>
                            <div
                                class="text-[12.25px] text-[var(--p-text-muted-color)]"
                            >
                                {{ plusAncienneLabel }}
                            </div>
                        </template>
                        <span v-else class="text-[var(--p-text-muted-color)]"
                            >—</span
                        >
                    </div>
                </div>
            </div>
        </div>

        <!-- Fin de la ligne 2 : rejetées. -->
        <div v-else :class="CLASSE_COLONNE" data-testid="kpi-rejetees">
            <div :class="CARTE_APOLLO">
                <span
                    :class="CLASSE_TITRE"
                    title="Dépenses de la période dont le statut actuel est « Rejeté ». Exclues du total des dépenses. Le cercle donne le taux de rejet : rejetées / (validées + rejetées)."
                    >Dépenses rejetées</span
                >
                <div class="mt-3.5 flex items-start justify-between">
                    <div class="w-7/12">
                        <span
                            :class="CLASSE_MONTANT"
                            :title="montantGnf(rejetees.montant)"
                            data-slot="montant"
                            >{{ montantCompact(rejetees.montant) }}</span
                        >
                        <span :class="CLASSE_DEVISE">GNF</span>
                        <div
                            class="font-medium text-[var(--p-text-muted-color)]"
                            data-slot="nombre"
                        >
                            {{ nombreDepenses(rejetees.nombre) }}
                        </div>
                    </div>
                    <div class="w-5/12 text-right">
                        <!-- Knob d'Apollo, comme la carte « Payées » de Stat-Commissions. -->
                        <Knob
                            :model-value="knob"
                            :value-template="knobTexte"
                            readonly
                            :stroke-width="2"
                            :size="90"
                            class="-mt-7 ml-auto inline-block [&_.p-knob-text]:text-[18.2px]!"
                            aria-label="Taux de rejet des dépenses"
                            data-slot="taux"
                        />
                    </div>
                </div>
            </div>
        </div>
    </template>
</template>
