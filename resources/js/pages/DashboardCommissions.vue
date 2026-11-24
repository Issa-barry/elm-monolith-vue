<script setup lang="ts">
import CommissionsEvolutionWidget from '@/components/dashboard/commissions/CommissionsEvolutionWidget.vue';
import CommissionsRepartitionWidget from '@/components/dashboard/commissions/CommissionsRepartitionWidget.vue';
import CommissionsStatsWidget from '@/components/dashboard/commissions/CommissionsStatsWidget.vue';
import SelecteurAgence from '@/components/filters/SelecteurAgence.vue';
import InfoTooltip from '@/components/InfoTooltip.vue';
import SelecteurPeriode from '@/components/periodes/SelecteurPeriode.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { parametresPeriode } from '@/lib/periodes';
import { dashboard } from '@/routes';
import { commissions } from '@/routes/dashboard';
import { type BreadcrumbItem } from '@/types';
import type {
    EvolutionCommissions,
    IndicateursCommissions,
    RepartitionCommission,
} from '@/types/dashboard-commissions';
import type { PeriodeFiltre } from '@/types/periode';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

// Tableau de bord des commissions (docs/tableau-de-bord-commissions.md) : reproduction du tableau
// de bord Ecommerce d'Apollo (grille 12 colonnes, gouttière 2rem = 28 px), sans les blocs Recent
// Sales et Top Products. Tous les chiffres viennent du serveur, déjà limités aux agences de
// l'utilisateur. Deux filtres en tête de page pilotent les quatre cartes et les deux graphiques :
// l'agence et la période (Stat-Ventes utilise le même SelecteurPeriode). Chacun conserve l'autre.
const props = defineProps<{
    periode: PeriodeFiltre;
    /** Période de comparaison des évolutions en % ; null si aucune comparaison n'est possible. */
    libelle_comparaison: string | null;
    /** Agences que l'utilisateur peut choisir (son périmètre), vérifiées par le serveur. */
    agences: { value: string; label: string }[];
    /** Agence choisie ; null = toutes les agences du périmètre. */
    agence: string | null;
    /** Vrai si le périmètre est limité aux agences de l'utilisateur. */
    perimetre_limite: boolean;
    indicateurs: IndicateursCommissions;
    taux_paiement: number | null;
    evolution: EvolutionCommissions;
    repartition: RepartitionCommission[];
}>();

// Libellé du périmètre affiché : l'agence choisie, sinon toutes les agences visibles.
const libelleAgences = computed(() => {
    if (props.agence !== null) {
        const choisie = props.agences.find((a) => a.value === props.agence);
        return `Agence : ${choisie?.label ?? '—'}`;
    }
    if (!props.perimetre_limite) return 'Toutes les agences';

    return props.agences.length > 0
        ? `Toutes vos agences : ${props.agences.map((a) => a.label).join(', ')}`
        : 'Aucune agence';
});

const filtreAgence = computed((): Record<string, string> => {
    const filtre: Record<string, string> = {};
    if (props.agence !== null) filtre.site_id = props.agence;

    return filtre;
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: dashboard().url },
    { title: 'Stat-Commissions', href: commissions().url },
];
</script>

<template>
    <Head title="Tableau de bord — Commissions" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div
                    class="flex min-w-0 items-center gap-1.5"
                    data-testid="dashboard-commissions-contexte"
                >
                    <p
                        class="text-sm font-medium"
                        data-testid="dashboard-commissions-periode"
                    >
                        {{ periode.libelle }}
                        <span
                            class="text-muted-foreground"
                            data-testid="dashboard-commissions-agences"
                        >
                            · {{ libelleAgences }}</span
                        >
                    </p>
                    <!-- Période de comparaison des évolutions en % : dans la bulle d'information. -->
                    <InfoTooltip label="Période de comparaison des évolutions">
                        <span data-testid="dashboard-commissions-comparaison">
                            <template v-if="libelle_comparaison">
                                Évolutions comparées à la période
                                {{ libelle_comparaison.toLowerCase() }}
                                (même périmètre d'agences).
                            </template>
                            <template v-else>
                                Pas de période de comparaison : évolutions non
                                calculées.
                            </template>
                        </span>
                    </InfoTooltip>
                </div>
                <div class="flex max-w-full flex-wrap items-start gap-2">
                    <SelecteurAgence
                        v-if="agences.length > 1"
                        :url="commissions().url"
                        :agences="agences"
                        :agence="agence"
                        :parametres="parametresPeriode(periode)"
                    />
                    <SelecteurPeriode
                        :url="commissions().url"
                        :periode="periode"
                        :parametres="filtreAgence"
                    />
                </div>
            </div>

            <div class="grid grid-cols-12 gap-7">
                <CommissionsStatsWidget
                    :indicateurs="indicateurs"
                    :tranches="evolution.tranches"
                    :taux-paiement="taux_paiement"
                />

                <div class="col-span-12 xl:col-span-9">
                    <CommissionsEvolutionWidget
                        :tranches="evolution.tranches"
                    />
                </div>
                <div class="col-span-12 xl:col-span-3">
                    <CommissionsRepartitionWidget :repartition="repartition" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
