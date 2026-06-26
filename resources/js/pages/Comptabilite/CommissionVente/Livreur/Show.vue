<script setup lang="ts">
import AuditTimeline from '@/components/AuditTimeline.vue';
import CommissionDetailHeader from '@/components/commission/CommissionDetailHeader.vue';
import CommissionDetailTable from '@/components/commission/CommissionDetailTable.vue';
import CommissionDetailTabs from '@/components/commission/CommissionDetailTabs.vue';
import CommissionExpensesTable from '@/components/commission/CommissionExpensesTable.vue';
import CommissionPaymentDialog from '@/components/commission/CommissionPaymentDialog.vue';
import CommissionPaymentsTable from '@/components/commission/CommissionPaymentsTable.vue';
import CommissionPeriodSelect from '@/components/commission/CommissionPeriodSelect.vue';
import CommissionSummaryCards from '@/components/commission/CommissionSummaryCards.vue';
import CommissionVehiculeSelect from '@/components/commission/CommissionVehiculeSelect.vue';
import { useCommissionVehiculeFilter } from '@/composables/useCommissionVehiculeFilter';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import type {
    CommissionDetailRow,
    CommissionDetailTab,
    CommissionExpenseRow,
    CommissionPaymentRow,
    CommissionSummary,
    ModePaiementOption,
    PeriodeOption,
} from '@/types/commission';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    livreur: { id: string; nom: string; telephone: string | null };
    commission_summary: CommissionSummary;
    commission_details: CommissionDetailRow[];
    payments: CommissionPaymentRow[];
    expenses: CommissionExpenseRow[];
    modes_paiement: ModePaiementOption[];
    periode_courante: string;
    periode_courante_label: string;
    selected_periode: string;
    periodes_disponibles: PeriodeOption[];
    can_payer: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Comptabilité', href: '/comptabilite' },
    { title: 'Commission vente', href: '/comptabilite/commissions/vente' },
    { title: props.livreur.nom, href: '' },
];

const periodeFiltre = ref(props.selected_periode ?? '');

function changePeriode(code: string) {
    periodeFiltre.value = code;
    const params: Record<string, string> = {};
    if (code) params.periode = code;
    router.get(
        `/comptabilite/commissions/vente/livreurs/${props.livreur.id}`,
        params,
        { preserveScroll: true, replace: true },
    );
}

const activeTab = ref<CommissionDetailTab>('informations');
const showPaiementDialog = ref(false);

const { vehiculeOptions, selectedVehicules, filteredRows } =
    useCommissionVehiculeFilter(computed(() => props.commission_details));

const {
    vehiculeOptions: expenseVehiculeOptions,
    selectedVehicules: selectedExpenseVehicules,
    filteredRows: filteredExpenses,
} = useCommissionVehiculeFilter(computed(() => props.expenses));
</script>

<template>
    <Head :title="`Commission vente — ${livreur.nom}`" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-5xl space-y-6 px-4 py-6 sm:px-6">
            <CommissionDetailHeader
                :back-href="'/comptabilite/commissions/vente'"
                eyebrow="Livreur — vente"
                :title="livreur.nom"
                :telephone="livreur.telephone"
                :can-pay="can_payer && commission_summary.reste_a_payer > 0"
                :pay-label="`Payer ${formatGNF(commission_summary.reste_a_payer)}`"
                @pay="showPaiementDialog = true"
            />

            <CommissionSummaryCards :summary="commission_summary" />

            <CommissionDetailTabs
                v-model="activeTab"
                :counts="{
                    depenses: expenses.length,
                    paiements: payments.length,
                }"
            />

            <template v-if="activeTab === 'informations'">
                <div
                    class="overflow-hidden rounded-xl border bg-card shadow-sm"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3"
                    >
                        <div class="flex items-center gap-2">
                            <h2
                                class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                Détail par commande
                            </h2>
                            <span
                                class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                                >{{ filteredRows.length }}</span
                            >
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <CommissionVehiculeSelect
                                v-if="vehiculeOptions.length > 1"
                                v-model="selectedVehicules"
                                :options="vehiculeOptions"
                            />
                            <div class="w-full sm:w-64">
                                <CommissionPeriodSelect
                                    v-model="periodeFiltre"
                                    :periodes-disponibles="periodes_disponibles"
                                    @update:model-value="changePeriode"
                                />
                            </div>
                        </div>
                    </div>
                    <CommissionDetailTable
                        :rows="filteredRows"
                        :empty-message="
                            selectedVehicules.length > 0
                                ? 'Aucune ligne pour ce véhicule.'
                                : undefined
                        "
                    />
                </div>
            </template>

            <template v-if="activeTab === 'depenses'">
                <div
                    class="overflow-hidden rounded-xl border bg-card shadow-sm"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3"
                    >
                        <h2 class="text-sm font-semibold">
                            Dépenses imputées à ce livreur
                        </h2>
                        <div class="flex flex-wrap items-center gap-2">
                            <CommissionVehiculeSelect
                                v-if="expenseVehiculeOptions.length > 1"
                                v-model="selectedExpenseVehicules"
                                :options="expenseVehiculeOptions"
                            />
                            <div class="w-full sm:w-64">
                                <CommissionPeriodSelect
                                    v-model="periodeFiltre"
                                    :periodes-disponibles="periodes_disponibles"
                                    @update:model-value="changePeriode"
                                />
                            </div>
                        </div>
                    </div>
                    <CommissionExpensesTable
                        :rows="filteredExpenses"
                        :empty-message="
                            selectedExpenseVehicules.length > 0
                                ? 'Aucune dépense pour ce véhicule.'
                                : 'Aucune dépense imputée à ce livreur.'
                        "
                    />
                </div>
            </template>

            <template v-if="activeTab === 'paiements'">
                <div
                    class="overflow-hidden rounded-xl border bg-card shadow-sm"
                >
                    <div class="border-b px-4 py-3">
                        <h2 class="text-sm font-semibold">
                            Paiements enregistrés
                        </h2>
                    </div>
                    <CommissionPaymentsTable
                        :rows="payments"
                        :modes-paiement="modes_paiement"
                    />
                </div>
            </template>

            <template v-if="activeTab === 'historique'">
                <div class="rounded-xl border bg-card p-5">
                    <AuditTimeline
                        auditable-type="App\Models\Livreur"
                        :auditable-id="livreur.id"
                        module="commission_vente"
                    />
                </div>
            </template>
        </div>
    </AppLayout>

    <CommissionPaymentDialog
        v-model:visible="showPaiementDialog"
        :beneficiaire-nom="livreur.nom"
        :solde-a-payer="commission_summary.reste_a_payer"
        :modes-paiement="modes_paiement"
        :payment-route="`/comptabilite/commissions/vente/livreurs/${livreur.id}/paiements`"
    />
</template>
