<script setup lang="ts">
import VehiculesAConfigurerWidget from '@/components/dashboard/vehicules/VehiculesAConfigurerWidget.vue';
import VehiculesCartesWidget from '@/components/dashboard/vehicules/VehiculesCartesWidget.vue';
import VehiculesCategoriesWidget from '@/components/dashboard/vehicules/VehiculesCategoriesWidget.vue';
import VehiculesClassementWidget from '@/components/dashboard/vehicules/VehiculesClassementWidget.vue';
import VehiculesOperationsWidget from '@/components/dashboard/vehicules/VehiculesOperationsWidget.vue';
import VehiculesRotationsWidget from '@/components/dashboard/vehicules/VehiculesRotationsWidget.vue';
import SelecteurAgence from '@/components/filters/SelecteurAgence.vue';
import InfoTooltip from '@/components/InfoTooltip.vue';
import SelecteurPeriode from '@/components/periodes/SelecteurPeriode.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { parametresPeriode } from '@/lib/periodes';
import { dashboard } from '@/routes';
import { vehicules as routeVehicules } from '@/routes/dashboard';
import { type BreadcrumbItem } from '@/types';
import type {
    ActiviteVehicules,
    BlocsVehicules,
    CategorieVehicules,
    DepensesVehicules,
    EvolutionVehicules,
    OperationVehicules,
    ParcVehicules,
    VehiculeAConfigurer,
    VehiculeClasse,
} from '@/types/dashboard-vehicules';
import type { PeriodeFiltre } from '@/types/periode';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

// Tableau de bord du parc, Stat-Véhicules V1 (docs/tableau-de-bord-vehicules.md), sur la grille et
// les cartes Apollo des autres tableaux de bord. Tous les chiffres viennent du serveur : le parc à
// l'agence actuelle des véhicules, l'activité et les dépenses à l'agence de l'opération. Un bloc
// absent (null) est celui d'une source que l'utilisateur ne peut pas consulter (ventes, logistique,
// dépenses). Deux filtres : l'agence et la période ; l'état du parc est toujours celui du jour.
const props = defineProps<{
    periode: PeriodeFiltre;
    agences: { value: string; label: string }[];
    agence: string | null;
    perimetre_limite: boolean;
    blocs: BlocsVehicules;
    parc: ParcVehicules;
    operations: OperationVehicules[];
    activite: ActiviteVehicules | null;
    evolution: EvolutionVehicules | null;
    categories: CategorieVehicules[];
    depenses: DepensesVehicules | null;
    vehicules: VehiculeClasse[];
    a_configurer: VehiculeAConfigurer[];
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

// Sources non consultables, rappelées sous l'en-tête plutôt que des zéros sans explication.
const sourcesAbsentes = computed(() =>
    [
        props.blocs.ventes ? null : 'ventes',
        props.blocs.transferts ? null : 'transferts',
        props.blocs.depenses ? null : 'dépenses',
    ].filter((s): s is string => s !== null),
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: dashboard().url },
    { title: 'Stat-Véhicules', href: routeVehicules().url },
];
</script>

<template>
    <Head><title>Tableau de bord — Véhicules</title></Head>

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div
                    class="flex min-w-0 items-center gap-1.5"
                    data-testid="dashboard-vehicules-contexte"
                >
                    <p
                        class="text-sm font-medium"
                        data-testid="dashboard-vehicules-periode"
                    >
                        {{ periode.libelle }}
                        <span
                            class="text-muted-foreground"
                            data-testid="dashboard-vehicules-agences"
                            >· {{ libelleAgences }}</span
                        >
                    </p>
                    <InfoTooltip label="Lecture du tableau de bord">
                        <span data-testid="dashboard-vehicules-aide">
                            L’état du parc est celui du jour ; la période
                            s’applique aux rotations, aux quantités chargées,
                            aux retours et aux dépenses. Aucune donnée de panne,
                            d’entretien ni de position GPS n’est enregistrée : «
                            opérationnel » ne tient compte que de la
                            configuration du véhicule.
                            <template v-if="perimetre_limite">
                                Périmètre : vos agences de rattachement.
                            </template>
                        </span>
                    </InfoTooltip>
                </div>
                <div class="flex max-w-full flex-wrap items-start gap-2">
                    <SelecteurAgence
                        v-if="agences.length > 1"
                        :url="routeVehicules().url"
                        :agences="agences"
                        :agence="agence"
                        :parametres="parametresPeriode(periode)"
                    />
                    <SelecteurPeriode
                        :url="routeVehicules().url"
                        :periode="periode"
                        :parametres="filtreAgence"
                    />
                </div>
            </div>

            <div
                class="-mt-2 space-y-1 text-[13px] text-[var(--p-text-muted-color)]"
            >
                <p data-testid="dashboard-vehicules-agence-rattachement">
                    Parc : agence actuelle de chaque véhicule. Activité et
                    dépenses : agence de l’opération (vente : agence de la
                    commande ; transfert : agence de départ ; dépense : agence
                    de la dépense).
                </p>
                <p
                    v-if="sourcesAbsentes.length > 0"
                    data-testid="dashboard-vehicules-sources-absentes"
                >
                    Non affiché faute d’accès :
                    {{ sourcesAbsentes.join(', ') }}.
                </p>
            </div>

            <div class="grid grid-cols-12 gap-7">
                <VehiculesCartesWidget
                    :blocs="blocs"
                    :parc="parc"
                    :operations="operations"
                    :activite="activite"
                    :depenses="depenses"
                />

                <div
                    class="col-span-12"
                    :class="{ 'xl:col-span-7': evolution !== null }"
                >
                    <VehiculesOperationsWidget
                        :operations="operations"
                        :blocs="blocs"
                    />
                </div>
                <div v-if="evolution" class="col-span-12 xl:col-span-5">
                    <VehiculesRotationsWidget :evolution="evolution" />
                </div>

                <div class="col-span-12">
                    <VehiculesCategoriesWidget :lignes="categories" />
                </div>

                <div class="col-span-12">
                    <VehiculesClassementWidget
                        :lignes="vehicules"
                        :activite="activite !== null"
                        :depenses="depenses !== null"
                    />
                </div>

                <div class="col-span-12">
                    <VehiculesAConfigurerWidget :lignes="a_configurer" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
