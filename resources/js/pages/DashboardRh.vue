<script setup lang="ts">
import RhAgencesWidget from '@/components/dashboard/rh/RhAgencesWidget.vue';
import RhCartesWidget from '@/components/dashboard/rh/RhCartesWidget.vue';
import RhDossiersWidget from '@/components/dashboard/rh/RhDossiersWidget.vue';
import RhFinsCddWidget from '@/components/dashboard/rh/RhFinsCddWidget.vue';
import RhMotifsWidget from '@/components/dashboard/rh/RhMotifsWidget.vue';
import SelecteurAgence from '@/components/filters/SelecteurAgence.vue';
import InfoTooltip from '@/components/InfoTooltip.vue';
import SelecteurPeriode from '@/components/periodes/SelecteurPeriode.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { parametresPeriode } from '@/lib/periodes';
import { dashboard } from '@/routes';
import { rh } from '@/routes/dashboard';
import { type BreadcrumbItem } from '@/types';
import type {
    ACompleterRh,
    AbsencesRh,
    BlocsRh,
    ContratsRh,
    EffectifAgenceRh,
    EffectifsRh,
    FinCddRh,
} from '@/types/dashboard-rh';
import type { PeriodeFiltre } from '@/types/periode';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

// Tableau de bord RH, Stat-RH lot R1 (docs/tableau-de-bord-rh.md) : effectifs, contrats, absences
// et alertes, sur la grille et les cartes Apollo des autres tableaux de bord. Tous les chiffres
// viennent du serveur, limités au périmètre d'agences des listes RH ; un bloc absent (null) est
// celui d'une liste que l'utilisateur ne peut pas consulter. Deux filtres : l'agence et la période ;
// effectifs et contrats sont toujours ceux du jour.
const props = defineProps<{
    periode: PeriodeFiltre;
    agences: { value: string; label: string }[];
    agence: string | null;
    perimetre_limite: boolean;
    blocs: BlocsRh;
    effectifs: EffectifsRh | null;
    effectifs_agences: EffectifAgenceRh[] | null;
    a_completer: ACompleterRh | null;
    contrats: ContratsRh | null;
    fins_cdd: FinCddRh[] | null;
    absences: AbsencesRh | null;
}>();

const libelleAgences = computed(() => {
    if (props.agence !== null) {
        const choisie = props.agences.find((a) => a.value === props.agence);
        return `Agence : ${choisie?.label ?? '—'}`;
    }
    if (!props.perimetre_limite) return 'Toutes les agences';

    return props.agences.length > 0
        ? `Vos agences : ${props.agences.map((a) => a.label).join(', ')}`
        : 'Aucune agence';
});

const filtreAgence = computed(
    (): Record<string, string> =>
        props.agence !== null ? { site_id: props.agence } : {},
);

// Motifs et fins de CDD côte à côte quand les deux blocs sont visibles, sinon pleine largeur.
const deuxColonnes = computed(
    () => props.absences !== null && props.fins_cdd !== null,
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: dashboard().url },
    { title: 'Stat-RH', href: rh().url },
];
</script>

<template>
    <Head><title>Tableau de bord — RH</title></Head>

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div
                    class="flex min-w-0 items-center gap-1.5"
                    data-testid="dashboard-rh-contexte"
                >
                    <p
                        class="text-sm font-medium"
                        data-testid="dashboard-rh-periode"
                    >
                        {{ periode.libelle }}
                        <span
                            class="text-muted-foreground"
                            data-testid="dashboard-rh-agences"
                            >· {{ libelleAgences }}</span
                        >
                    </p>
                    <InfoTooltip label="Lecture du tableau de bord">
                        <span data-testid="dashboard-rh-aide">
                            Effectifs et contrats sont ceux du jour ; la période
                            s’applique aux entrées, aux sorties et aux absences
                            (jours calendaires, jusqu’à aujourd’hui, dans la
                            période d’emploi de chaque salarié). Le taux
                            d’absentéisme ne retient que les salariés dont la
                            date d’embauche est connue.
                            <template v-if="perimetre_limite">
                                Périmètre des listes RH : vos agences de
                                rattachement.
                            </template>
                        </span>
                    </InfoTooltip>
                </div>
                <div class="flex max-w-full flex-wrap items-start gap-2">
                    <SelecteurAgence
                        v-if="agences.length > 1"
                        :url="rh().url"
                        :agences="agences"
                        :agence="agence"
                        :parametres="parametresPeriode(periode)"
                    />
                    <SelecteurPeriode
                        :url="rh().url"
                        :periode="periode"
                        :parametres="filtreAgence"
                    />
                </div>
            </div>

            <!-- Limite assumée (revue du 26/11/2026) : l'historique des affectations n'est pas lu. -->
            <p
                class="-mt-2 text-[13px] text-[var(--p-text-muted-color)]"
                data-testid="dashboard-rh-agence-actuelle"
            >
                Statistiques rattachées à l’agence actuelle de chaque salarié :
                un salarié muté pendant la période est compté entièrement dans
                sa nouvelle agence, absences passées comprises.
            </p>

            <div class="grid grid-cols-12 gap-7">
                <RhCartesWidget
                    :effectifs="effectifs"
                    :sans-date-embauche="
                        a_completer?.sans_date_embauche ?? null
                    "
                    :contrats="contrats"
                    :fins-cdd="fins_cdd"
                    :absences="absences"
                />

                <template v-if="effectifs_agences !== null">
                    <div class="col-span-12 xl:col-span-8">
                        <RhAgencesWidget :lignes="effectifs_agences" />
                    </div>
                    <div v-if="a_completer" class="col-span-12 xl:col-span-4">
                        <RhDossiersWidget :dossiers="a_completer" />
                    </div>
                </template>

                <div
                    v-if="absences"
                    class="col-span-12"
                    :class="{ 'xl:col-span-5': deuxColonnes }"
                >
                    <RhMotifsWidget :lignes="absences.par_motif" />
                </div>
                <div
                    v-if="fins_cdd"
                    class="col-span-12"
                    :class="{ 'xl:col-span-7': deuxColonnes }"
                >
                    <RhFinsCddWidget :lignes="fins_cdd" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
