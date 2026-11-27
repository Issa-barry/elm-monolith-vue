<script setup lang="ts">
import DepensesAgencesWidget from '@/components/dashboard/depenses/DepensesAgencesWidget.vue';
import DepensesEvolutionWidget from '@/components/dashboard/depenses/DepensesEvolutionWidget.vue';
import DepensesStatsWidget from '@/components/dashboard/depenses/DepensesStatsWidget.vue';
import DepensesTypesWidget from '@/components/dashboard/depenses/DepensesTypesWidget.vue';
import SelecteurAgence from '@/components/filters/SelecteurAgence.vue';
import InfoTooltip from '@/components/InfoTooltip.vue';
import SelecteurPeriode from '@/components/periodes/SelecteurPeriode.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { parametresPeriode } from '@/lib/periodes';
import { dashboard } from '@/routes';
import { depenses } from '@/routes/dashboard';
import { type BreadcrumbItem } from '@/types';
import type {
    EnAttenteDepenses,
    EvolutionDepenses,
    IndicateursDepenses,
    PartDepenses,
    RejeteesDepenses,
} from '@/types/dashboard-depenses';
import type { PeriodeFiltre } from '@/types/periode';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

// Tableau de bord des dépenses, Stat-Dépenses (docs/tableau-de-bord-depenses.md) : même grille et
// mêmes cartes Apollo que Stat-Commissions. Tous les chiffres viennent du serveur, déjà limités au
// périmètre de la liste des dépenses (DepensePerimetre). Deux filtres en tête de page pilotent les
// six cartes et les trois graphiques : l'agence et la période. Chacun conserve l'autre.
const props = defineProps<{
    periode: PeriodeFiltre;
    /** Période de comparaison des évolutions en % ; null si aucune comparaison n'est possible. */
    libelle_comparaison: string | null;
    /** Agences que l'utilisateur peut choisir (son périmètre), vérifiées par le serveur. */
    agences: { value: string; label: string }[];
    /** Agence choisie ; null = tout le périmètre. */
    agence: string | null;
    /** Vrai si le périmètre n'est pas « toutes les agences ». */
    perimetre_limite: boolean;
    indicateurs: IndicateursDepenses;
    en_attente: EnAttenteDepenses;
    rejetees: RejeteesDepenses;
    evolution: EvolutionDepenses;
    repartition_types: PartDepenses[];
    repartition_agences: PartDepenses[];
}>();

// Libellé du périmètre affiché : l'agence choisie, sinon tout le périmètre visible.
const libelleAgences = computed(() => {
    if (props.agence !== null) {
        const choisie = props.agences.find((a) => a.value === props.agence);
        return `Agence : ${choisie?.label ?? '—'}`;
    }
    if (!props.perimetre_limite) return 'Toutes les agences';

    return props.agences.length > 0
        ? `Vos agences : ${props.agences.map((a) => a.label).join(', ')}`
        : 'Vos dépenses';
});

const filtreAgence = computed((): Record<string, string> => {
    const filtre: Record<string, string> = {};
    if (props.agence !== null) filtre.site_id = props.agence;

    return filtre;
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: dashboard().url },
    { title: 'Stat-Dépenses', href: depenses().url },
];
</script>

<template>
    <Head><title>Tableau de bord — Dépenses</title></Head>

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div
                    class="flex min-w-0 items-center gap-1.5"
                    data-testid="dashboard-depenses-contexte"
                >
                    <p
                        class="text-sm font-medium"
                        data-testid="dashboard-depenses-periode"
                    >
                        {{ periode.libelle }}
                        <span
                            class="text-muted-foreground"
                            data-testid="dashboard-depenses-agences"
                        >
                            · {{ libelleAgences }}</span
                        >
                    </p>
                    <InfoTooltip label="Périmètre et comparaison">
                        <span data-testid="dashboard-depenses-comparaison">
                            <template v-if="libelle_comparaison">
                                Évolutions comparées à la période
                                {{ libelle_comparaison.toLowerCase() }}
                                (même périmètre).
                            </template>
                            <template v-else>
                                Pas de période de comparaison : évolutions non
                                calculées.
                            </template>
                            <template v-if="perimetre_limite">
                                Périmètre de la liste des dépenses : vos
                                agences, celles où vous validez et les dépenses
                                que vous avez saisies.
                            </template>
                        </span>
                    </InfoTooltip>
                </div>
                <div class="flex max-w-full flex-wrap items-start gap-2">
                    <SelecteurAgence
                        v-if="agences.length > 1"
                        :url="depenses().url"
                        :agences="agences"
                        :agence="agence"
                        :parametres="parametresPeriode(periode)"
                    />
                    <SelecteurPeriode
                        :url="depenses().url"
                        :periode="periode"
                        :parametres="filtreAgence"
                    />
                </div>
            </div>

            <div class="grid grid-cols-12 gap-7">
                <DepensesStatsWidget
                    :indicateurs="indicateurs"
                    :en-attente="en_attente"
                    :rejetees="rejetees"
                    :tranches="evolution.tranches"
                />

                <div class="col-span-12 xl:col-span-8">
                    <DepensesEvolutionWidget :tranches="evolution.tranches" />
                </div>
                <div class="col-span-12 xl:col-span-4">
                    <DepensesTypesWidget :repartition="repartition_types" />
                </div>
                <div class="col-span-12">
                    <DepensesAgencesWidget :agences="repartition_agences" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
