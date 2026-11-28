<script setup lang="ts">
import HeaderWidget from '@/components/dashboard/banking/HeaderWidget.vue';
import MobileQuickMenu from '@/components/dashboard/banking/MobileQuickMenu.vue';
import StatsBankingWidget from '@/components/dashboard/banking/StatsBankingWidget.vue';
import CaParSiteDoughnutWidget from '@/components/dashboard/ventes/CaParSiteDoughnutWidget.vue';
import EvolutionCAWidget from '@/components/dashboard/ventes/EvolutionCAWidget.vue';
import PacksPieWidget from '@/components/dashboard/ventes/PacksPieWidget.vue';
import VehiculeCategoryWidget from '@/components/dashboard/ventes/VehiculeCategoryWidget.vue';
import VentesParProcessusWidget from '@/components/dashboard/ventes/VentesParProcessusWidget.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import type { PeriodeFiltre } from '@/types/periode';
import { Head } from '@inertiajs/vue3';

interface StatsFactures {
    total_count: number;
    total_montant: number;
    payees_count: number;
    payees_montant: number;
    total_encaisse: number;
    impayees_count: number;
    annulees_count: number;
    reste_a_encaisser: number;
}

/** Une barre du graphique : un jour, ou un mois au-delà de 31 jours (calculé par le serveur). */
interface TrancheVentes {
    cle: string;
    label: string;
    payees: number;
    partielles: number;
    impayees: number;
}

interface SiteData {
    nom: string;
    montant: number;
}

interface TypeVehiculeData {
    label: string;
    montant: number;
}

interface ProcessusData {
    code: string;
    label: string;
    montant: number;
    nb_factures: number;
}

interface ProduitData {
    nom: string;
    total: number;
}

/**
 * Page d'accueil du back-office. Stat-Ventes désactivé pour l'organisation (ADR 0071) : seuls
 * l'en-tête (QR compris) et le menu rapide mobile restent, le serveur n'envoie aucun chiffre.
 */
defineProps<{
    ventes_actif: boolean;
    stats_factures?: StatsFactures;
    evolution?: { granularite: 'jour' | 'mois'; tranches: TrancheVentes[] };
    ca_par_site?: SiteData[];
    ca_par_type_vehicule?: TypeVehiculeData[];
    ca_par_produit?: ProduitData[];
    ca_par_processus?: ProcessusData[];
    /** Filtre unique de la page : cartes, graphique et répartitions portent sur cette période. */
    periode?: PeriodeFiltre;
    qr_payload: string | null;
    /** null = toute l'organisation ; sinon agences auxquelles les chiffres sont limités. */
    agences?: string[] | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Tableau de bord',
        href: dashboard().url,
    },
];
</script>

<template>
    <Head title="Tableau de bord" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4 sm:p-6">
            <HeaderWidget :periode="periode" :qr-payload="qr_payload" />

            <template
                v-if="
                    ventes_actif &&
                    periode &&
                    stats_factures &&
                    evolution &&
                    ca_par_site &&
                    ca_par_type_vehicule &&
                    ca_par_produit &&
                    ca_par_processus
                "
            >
                <p
                    class="text-xs text-muted-foreground"
                    data-testid="dashboard-periode"
                >
                    {{ periode.libelle }}
                    <template v-if="agences">
                        — chiffres de vos agences :
                        {{ agences.length > 0 ? agences.join(', ') : 'aucune' }}
                    </template>
                </p>

                <div class="mt-4" data-testid="dashboard-ventes">
                    <StatsBankingWidget :stats="stats_factures" />
                </div>

                <div class="hidden grid-cols-12 gap-8 sm:grid">
                    <div class="col-span-12 xl:col-span-8">
                        <EvolutionCAWidget :evolution="evolution" />
                    </div>
                    <div class="col-span-12 xl:col-span-4">
                        <VehiculeCategoryWidget
                            :ca-par-type-vehicule="ca_par_type_vehicule"
                        />
                    </div>
                </div>

                <div class="hidden grid-cols-12 gap-8 sm:grid">
                    <div class="col-span-12 xl:col-span-4">
                        <VentesParProcessusWidget
                            :ca-par-processus="ca_par_processus"
                        />
                    </div>
                    <div class="col-span-12 xl:col-span-4">
                        <CaParSiteDoughnutWidget :ca-par-site="ca_par_site" />
                    </div>
                    <div class="col-span-12 xl:col-span-4">
                        <PacksPieWidget :ca-par-produit="ca_par_produit" />
                    </div>
                </div>
            </template>

            <MobileQuickMenu />
        </div>
    </AppLayout>
</template>
