<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import type { AppPageProps } from '@/types';
import type {
    SituationPeriode,
    SituationVentesData,
} from '@/types/vehicule-situation';
import { Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    CircleDollarSign,
    HandCoins,
    ShoppingBag,
    WalletCards,
} from 'lucide-vue-next';
import { computed } from 'vue';
import PaiementsChart from './PaiementsChart.vue';
import ProduitsVendusChart from './ProduitsVendusChart.vue';
import SituationKpiCard from './SituationKpiCard.vue';
import SituationSection from './SituationSection.vue';

const props = defineProps<{
    // Texte cherché par le filtre « Véhicule » de l'écran Ventes (nom ou immatriculation).
    vehiculeRecherche: string;
    periode: SituationPeriode;
    data: SituationVentesData;
}>();

const page = usePage<AppPageProps>();
const { can } = usePermissions();

// Le bouton mène à /backoffice/ventes (module Ventes + ventes.read) : jamais affiché sans les deux,
// même garde que l'entrée « Ventes » de la sidebar.
const peutVoirVentes = computed(
    () => can('ventes.read') && page.props.module_flags?.ventes !== false,
);

// Reprend le véhicule et la période affichés, pour retrouver dans Ventes les commandes de la synthèse.
// Ventes affiche aussi brouillons/annulées (jamais comptés ici) et ne liste que les ventes
// standard (les distributions ont leur propre écran) : le total peut donc différer.
const lienVentes = computed(() => {
    const params = new URLSearchParams({ vehicule: props.vehiculeRecherche });
    if (props.periode.date_debut) {
        params.set('date_debut', props.periode.date_debut);
    }
    if (props.periode.date_fin) {
        params.set('date_fin', props.periode.date_fin);
    }

    return `/backoffice/ventes?${params.toString()}`;
});
</script>

<template>
    <SituationSection
        title="Activité commerciale"
        description="Ventes du véhicule, hors brouillons et commandes annulées"
    >
        <template #actions>
            <Link
                v-if="peutVoirVentes"
                :href="lienVentes"
                data-testid="situation-voir-ventes"
            >
                <Button variant="outline" size="sm">
                    Voir les ventes
                    <ArrowRight class="ml-1.5 h-4 w-4" />
                </Button>
            </Link>
        </template>

        <div class="grid grid-cols-1 gap-4 @lg:grid-cols-2 @5xl:grid-cols-4">
            <SituationKpiCard
                label="CA vendu"
                :value="data.kpis.ca_vendu"
                unit="GNF"
                :icon="CircleDollarSign"
                tone="primary"
            />
            <SituationKpiCard
                label="Encaissé"
                :value="data.kpis.encaisse"
                unit="GNF"
                :icon="HandCoins"
                tone="success"
            />
            <SituationKpiCard
                label="Reste à payer"
                :value="data.kpis.reste_du"
                unit="GNF"
                :icon="WalletCards"
                tone="warning"
                :highlight="data.kpis.reste_du > 0"
            />
            <SituationKpiCard
                label="Nombre de ventes"
                :value="data.kpis.nb_ventes"
                :icon="ShoppingBag"
                tone="info"
            />
        </div>

        <div class="grid grid-cols-1 gap-4 @3xl:grid-cols-2">
            <ProduitsVendusChart :produits="data.produits" />
            <PaiementsChart :paiements="data.paiements" />
        </div>
    </SituationSection>
</template>
