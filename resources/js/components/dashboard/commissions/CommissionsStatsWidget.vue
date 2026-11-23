<script setup lang="ts">
import type {
    CompartimentCommission,
    IndicateursCommissions,
    TrancheCommissions,
} from '@/types/dashboard-commissions';
import Knob from 'primevue/knob';
import { computed } from 'vue';
import {
    CARTE_APOLLO,
    evolutionTexte,
    montantCompact,
    montantGnf,
    traceCourbe,
} from './apollo';

// Port de StatsEcommerceWidget.vue (Apollo) : quatre cartes `col-span-12 md:col-span-6
// xl:col-span-3` posées directement dans la grille de la page. Les trois premières gardent la
// mini-courbe d'Apollo (mêmes viewBox et épaisseurs), tracée ici avec les montants réels de chaque
// tranche ; la quatrième garde le Knob, qui montre le taux de paiement.
//
// Tailles relevées dans le rendu d'Apollo et non dans ses classes : la flèche (`text-xs`) y est
// affichée en 14 px par la règle de PrimeIcons. Les styles PrimeVue et PrimeIcons sont hors couche
// CSS et l'emportent sur les utilitaires Tailwind : les tailles qui les remplacent portent `!`.
const props = defineProps<{
    indicateurs: IndicateursCommissions;
    tranches: TrancheCommissions[];
    tauxPaiement: number | null;
}>();

interface CarteCourbe {
    id: CompartimentCommission;
    titre: string;
    definition: string;
    largeur: number;
    hauteur: number;
    epaisseur: string;
}

// Dimensions des tracés d'origine : 258 × 96 (trait 2 px) pour la première carte, 115 × 41 (1 px)
// pour les deux suivantes.
const CARTES: CarteCourbe[] = [
    {
        id: 'total_genere',
        titre: 'Commissions générées',
        definition:
            'Toutes les commissions de la période, hors commissions annulées.',
        largeur: 258,
        hauteur: 96,
        epaisseur: '2px',
    },
    {
        id: 'en_attente_periode',
        titre: 'En attente de validation',
        definition:
            "Commissions dont la période de paiement n'est pas encore validée : visibles, pas encore payables.",
        largeur: 115,
        hauteur: 41,
        epaisseur: '1px',
    },
    {
        id: 'payable',
        titre: 'À payer',
        definition: 'Reste à payer des commissions validées.',
        largeur: 115,
        hauteur: 41,
        epaisseur: '1px',
    },
];

type Tendance = 'hausse' | 'baisse' | 'neutre';

function tendance(evolution: number | null): Tendance {
    if (evolution === null || evolution === 0) return 'neutre';
    return evolution > 0 ? 'hausse' : 'baisse';
}

// Couleurs d'Apollo : vert en hausse, rose en baisse (texte et mini-courbe) ; gris sans tendance.
const COULEUR_TEXTE: Record<Tendance, string> = {
    hausse: 'text-[var(--p-green-500)]',
    baisse: 'text-[var(--p-pink-500)]',
    neutre: 'text-[var(--p-text-muted-color)]',
};

const cartes = computed(() =>
    CARTES.map((carte) => {
        const indicateur = props.indicateurs[carte.id];
        const sens = tendance(indicateur.evolution);

        return {
            ...carte,
            indicateur,
            sens,
            trace: traceCourbe(
                props.tranches.map((t) => t[carte.id]),
                carte.largeur,
                carte.hauteur,
            ),
            couleurCourbe:
                sens === 'baisse'
                    ? 'var(--p-pink-500)'
                    : 'var(--p-primary-color)',
        };
    }),
);

const payees = computed(() => ({
    indicateur: props.indicateurs.deja_paye,
    sens: tendance(props.indicateurs.deja_paye.evolution),
}));

const knob = computed(() => props.tauxPaiement ?? 0);
const knobTexte = computed(() =>
    props.tauxPaiement === null ? '—' : `${props.tauxPaiement}%`,
);

function libelleEvolution(evolution: number | null): string {
    return evolution === null ? '—' : evolutionTexte(evolution);
}

function astuceEvolution(evolution: number | null): string {
    return evolution === null
        ? 'Aucune comparaison possible : rien sur la période de comparaison.'
        : 'Évolution par rapport à la période de comparaison.';
}
</script>

<template>
    <div
        v-for="carte in cartes"
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
            <div class="mt-3.5 flex items-start justify-between">
                <div class="w-6/12">
                    <span
                        class="text-[31.5px] leading-[35px] font-bold whitespace-nowrap text-[var(--p-surface-900)] dark:text-[var(--p-surface-0)]"
                        :title="montantGnf(carte.indicateur.montant)"
                        data-slot="montant"
                        >{{ montantCompact(carte.indicateur.montant) }}</span
                    >
                    <span
                        class="ml-1 inline-block text-[14px] font-medium whitespace-nowrap text-[var(--p-text-muted-color)]"
                        >GNF</span
                    >
                    <div
                        :class="COULEUR_TEXTE[carte.sens]"
                        :title="astuceEvolution(carte.indicateur.evolution)"
                        data-slot="evolution"
                    >
                        <span class="font-medium">{{
                            libelleEvolution(carte.indicateur.evolution)
                        }}</span>
                        <i
                            v-if="carte.sens !== 'neutre'"
                            class="ml-[7px] text-[14px]! leading-[14px]!"
                            :class="
                                carte.sens === 'hausse'
                                    ? 'pi pi-arrow-up'
                                    : 'pi pi-arrow-down'
                            "
                        ></i>
                    </div>
                </div>
                <div class="w-6/12">
                    <svg
                        width="100%"
                        :viewBox="`0 0 ${carte.largeur} ${carte.hauteur}`"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                        aria-hidden="true"
                    >
                        <path
                            :d="carte.trace"
                            :style="{
                                strokeWidth: carte.epaisseur,
                                stroke: carte.couleurCourbe,
                            }"
                        />
                    </svg>
                </div>
            </div>
        </div>
    </div>
    <div
        class="col-span-12 md:col-span-6 xl:col-span-3"
        data-testid="kpi-deja_paye"
    >
        <div :class="CARTE_APOLLO">
            <span
                class="text-[15.75px] leading-[24.5px] font-semibold"
                title="Montant déjà versé sur les commissions de la période. Le cercle donne la part du généré déjà payée."
                >Payées</span
            >
            <div class="mt-3.5 flex items-start justify-between">
                <div class="w-6/12">
                    <span
                        class="text-[31.5px] leading-[35px] font-bold whitespace-nowrap text-[var(--p-surface-900)] dark:text-[var(--p-surface-0)]"
                        :title="montantGnf(payees.indicateur.montant)"
                        data-slot="montant"
                        >{{ montantCompact(payees.indicateur.montant) }}</span
                    >
                    <span
                        class="ml-1 inline-block text-[14px] font-medium whitespace-nowrap text-[var(--p-text-muted-color)]"
                        >GNF</span
                    >
                    <div
                        :class="COULEUR_TEXTE[payees.sens]"
                        :title="astuceEvolution(payees.indicateur.evolution)"
                        data-slot="evolution"
                    >
                        <span class="font-medium">{{
                            libelleEvolution(payees.indicateur.evolution)
                        }}</span>
                        <i
                            v-if="payees.sens !== 'neutre'"
                            class="ml-[7px] text-[14px]! leading-[14px]!"
                            :class="
                                payees.sens === 'hausse'
                                    ? 'pi pi-arrow-up'
                                    : 'pi pi-arrow-down'
                            "
                        ></i>
                    </div>
                </div>
                <div class="w-6/12 text-right">
                    <!-- Knob d'Apollo : 90 px, trait 2, décalé de -2rem/2rem (28 px). Texte à 1,3rem
                         d'Apollo (18,2 px) au lieu des 20,8 px qu'il prendrait à notre base de 16 px. -->
                    <Knob
                        :model-value="knob"
                        :value-template="knobTexte"
                        readonly
                        :stroke-width="2"
                        :size="90"
                        class="-mt-7 ml-7 [&_.p-knob-text]:text-[18.2px]!"
                        aria-label="Part des commissions générées déjà payée"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
