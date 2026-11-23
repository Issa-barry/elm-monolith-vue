<script setup lang="ts">
import CommissionsEvolutionWidget from '@/components/dashboard/commissions/CommissionsEvolutionWidget.vue';
import CommissionsRepartitionWidget from '@/components/dashboard/commissions/CommissionsRepartitionWidget.vue';
import CommissionsStatsWidget from '@/components/dashboard/commissions/CommissionsStatsWidget.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { commissions } from '@/routes/dashboard';
import { type BreadcrumbItem } from '@/types';
import type {
    EvolutionCommissions,
    IndicateursCommissions,
    RepartitionCommission,
} from '@/types/dashboard-commissions';
import type { PeriodeOption } from '@/types/periode';
import { Head } from '@inertiajs/vue3';

// Tableau de bord des commissions (docs/tableau-de-bord-commissions.md) : reproduction du tableau
// de bord Ecommerce d'Apollo (grille 12 colonnes, gouttière 2rem = 28 px), sans les blocs Recent
// Sales et Top Products. Tous les chiffres viennent du serveur, déjà limités aux agences de
// l'utilisateur.
defineProps<{
    periode: string;
    periodes: PeriodeOption[];
    libelle_periode: string;
    libelle_comparaison: string | null;
    /** null = toute l'organisation ; sinon agences auxquelles les chiffres sont limités. */
    agences: string[] | null;
    indicateurs: IndicateursCommissions;
    taux_paiement: number | null;
    evolution: EvolutionCommissions;
    repartition: RepartitionCommission[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: dashboard().url },
    { title: 'Stat-Commissions', href: commissions().url },
];
</script>

<template>
    <Head title="Tableau de bord — Commissions" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4 sm:p-6">
            <p
                class="text-xs text-muted-foreground"
                data-testid="dashboard-commissions-contexte"
            >
                {{ libelle_periode }}
                <template v-if="libelle_comparaison">
                    — évolutions comparées à la période
                    {{ libelle_comparaison.toLowerCase() }}
                </template>
                <template v-if="agences !== null">
                    — chiffres de vos agences :
                    {{ agences.length > 0 ? agences.join(', ') : 'aucune' }}
                </template>
            </p>

            <div class="grid grid-cols-12 gap-7">
                <CommissionsStatsWidget
                    :indicateurs="indicateurs"
                    :tranches="evolution.tranches"
                    :taux-paiement="taux_paiement"
                />

                <div class="col-span-12 xl:col-span-9">
                    <CommissionsEvolutionWidget
                        :tranches="evolution.tranches"
                        :periode="periode"
                        :periodes="periodes"
                    />
                </div>
                <div class="col-span-12 xl:col-span-3">
                    <CommissionsRepartitionWidget :repartition="repartition" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
