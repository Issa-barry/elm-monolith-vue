<script setup lang="ts">
import PaiementsChart from '@/components/situation/PaiementsChart.vue';
import ProduitsVendusChart from '@/components/situation/ProduitsVendusChart.vue';
import SituationEntete from '@/components/situation/SituationEntete.vue';
import SituationKpiCard from '@/components/situation/SituationKpiCard.vue';
import SituationSection from '@/components/situation/SituationSection.vue';
import { Button } from '@/components/ui/button';
import { formatGNF } from '@/lib/utils';
import type { AgentSituationData } from '@/types/agent-fiche';
import type { SituationPeriode } from '@/types/situation';
import { Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Banknote,
    CircleDollarSign,
    HandCoins,
    Receipt,
    ShoppingBag,
    WalletCards,
} from 'lucide-vue-next';

defineProps<{
    agentId: string;
    periode: SituationPeriode;
    data: AgentSituationData;
    /** Détail ligne à ligne (rapport d'activité ou « Ma situation ») ; null = pas de lien. */
    lienRapport: string | null;
}>();

function formatPourcentage(valeur: number): string {
    return `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 }).format(valeur)} %`;
}
</script>

<template>
    <div class="space-y-8" data-testid="agent-situation-panel">
        <SituationEntete
            titre="Situation de l'agent"
            :url="`/backoffice/users/${agentId}`"
            :periode="periode"
        />

        <SituationSection
            title="Activité commerciale"
            description="Ventes créées par l'agent, datées par leur facture, hors ventes annulées ou retournées"
        >
            <template v-if="lienRapport" #actions>
                <Link :href="lienRapport" data-testid="agent-voir-rapport">
                    <Button variant="outline" size="sm">
                        Voir le détail
                        <ArrowRight class="ml-1.5 h-4 w-4" />
                    </Button>
                </Link>
            </template>

            <div
                class="grid grid-cols-1 gap-4 @lg:grid-cols-2 @5xl:grid-cols-4"
            >
                <SituationKpiCard
                    label="CA vendu"
                    :value="data.ventes.kpis.ca_vendu"
                    unit="GNF"
                    :icon="CircleDollarSign"
                    tone="primary"
                />
                <SituationKpiCard
                    label="Encaissé sur ses ventes"
                    :value="data.ventes.kpis.encaisse"
                    unit="GNF"
                    :icon="HandCoins"
                    tone="success"
                />
                <SituationKpiCard
                    label="Reste à payer"
                    :value="data.ventes.kpis.reste_du"
                    unit="GNF"
                    :icon="WalletCards"
                    tone="warning"
                    :highlight="data.ventes.kpis.reste_du > 0"
                />
                <SituationKpiCard
                    label="Nombre de ventes"
                    :value="data.ventes.kpis.nb_ventes"
                    :icon="ShoppingBag"
                    tone="info"
                />
            </div>

            <div class="grid grid-cols-1 gap-4 @3xl:grid-cols-2">
                <ProduitsVendusChart :produits="data.ventes.produits" />
                <PaiementsChart :paiements="data.ventes.paiements" />
            </div>
        </SituationSection>

        <SituationSection
            title="Encaissements réalisés"
            description="Paiements enregistrés par l'agent sur la période, quelle que soit la vente"
        >
            <div class="grid grid-cols-1 gap-4 @lg:grid-cols-2">
                <SituationKpiCard
                    label="Montant encaissé"
                    :value="data.encaissements.montant"
                    unit="GNF"
                    :icon="Banknote"
                    tone="success"
                />
                <SituationKpiCard
                    label="Nombre d'encaissements"
                    :value="data.encaissements.nombre"
                    :icon="Receipt"
                    tone="info"
                />
            </div>

            <div
                class="rounded-xl border bg-card p-5 sm:p-6"
                data-testid="agent-encaissements-moyens"
            >
                <h4 class="text-base font-semibold">
                    Répartition par moyen de paiement
                </h4>
                <p class="mt-1 text-xs text-muted-foreground">
                    Part de chaque moyen dans le montant encaissé par l'agent
                </p>

                <div
                    v-if="data.encaissements.nombre === 0"
                    class="mt-4 rounded-lg border border-dashed py-10 text-center"
                >
                    <p class="text-sm text-muted-foreground">
                        Aucun encaissement enregistré par l'agent sur cette
                        période.
                    </p>
                </div>

                <div v-else class="mt-5 overflow-x-auto">
                    <table class="w-full min-w-[340px] text-sm">
                        <thead class="text-xs text-muted-foreground">
                            <tr class="border-b">
                                <th
                                    class="pr-2 pb-2 text-left align-bottom font-medium"
                                >
                                    Moyen
                                </th>
                                <th
                                    class="px-2 pb-2 text-right align-bottom font-medium"
                                >
                                    Montant
                                </th>
                                <th
                                    class="px-2 pb-2 text-right align-bottom font-medium"
                                    title="Part du montant total encaissé"
                                >
                                    %
                                </th>
                                <th
                                    class="px-2 pb-2 text-right align-bottom font-medium"
                                >
                                    Nombre
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="moyen in data.encaissements.par_moyen"
                                :key="moyen.cle"
                                class="border-t"
                            >
                                <td class="py-2.5 pr-2 font-medium">
                                    {{ moyen.libelle }}
                                </td>
                                <td
                                    class="px-2 py-2.5 text-right font-semibold whitespace-nowrap tabular-nums"
                                >
                                    {{ formatGNF(moyen.montant) }}
                                </td>
                                <td
                                    class="px-2 py-2.5 text-right whitespace-nowrap tabular-nums"
                                >
                                    {{
                                        formatPourcentage(
                                            moyen.pourcentage_montant,
                                        )
                                    }}
                                </td>
                                <td
                                    class="px-2 py-2.5 text-right font-semibold tabular-nums"
                                >
                                    {{ moyen.nombre }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 font-semibold">
                                <td class="py-2.5 pr-2">Total</td>
                                <td
                                    class="px-2 py-2.5 text-right whitespace-nowrap tabular-nums"
                                >
                                    {{ formatGNF(data.encaissements.montant) }}
                                </td>
                                <td
                                    class="px-2 py-2.5 text-right whitespace-nowrap tabular-nums"
                                >
                                    100 %
                                </td>
                                <td class="px-2 py-2.5 text-right tabular-nums">
                                    {{ data.encaissements.nombre }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </SituationSection>
    </div>
</template>
