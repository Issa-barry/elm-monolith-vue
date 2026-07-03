<script setup lang="ts">
import AuditTimeline from '@/components/AuditTimeline.vue';
import CommissionDetailHeader from '@/components/commission/CommissionDetailHeader.vue';
import CommissionDetailTable from '@/components/commission/CommissionDetailTable.vue';
import CommissionDetailTabs from '@/components/commission/CommissionDetailTabs.vue';
import CommissionExpensesTable from '@/components/commission/CommissionExpensesTable.vue';
import CommissionPaymentDialog from '@/components/commission/CommissionPaymentDialog.vue';
import CommissionPaymentsTable from '@/components/commission/CommissionPaymentsTable.vue';
import CommissionSummaryCards from '@/components/commission/CommissionSummaryCards.vue';
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
} from '@/types/commission';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
    employe: { id: string; nom: string; telephone: string | null };
    commission_summary: CommissionSummary;
    commission_details: CommissionDetailRow[];
    payments: CommissionPaymentRow[];
    expenses: CommissionExpenseRow[];
    modes_paiement: ModePaiementOption[];
    can_payer: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité', href: '/backoffice/comptabilite' },
    { title: 'Paiement salaire', href: '/backoffice/comptabilite/salaires' },
    { title: props.employe.nom, href: '' },
];

const activeTab = ref<CommissionDetailTab>('informations');
const showPaiementDialog = ref(false);
</script>

<template>
    <Head :title="`Salaire — ${employe.nom}`" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-5xl space-y-6 px-4 py-6 sm:px-6">
            <CommissionDetailHeader
                :back-href="'/backoffice/comptabilite/salaires'"
                eyebrow="Salarié"
                :title="employe.nom"
                :telephone="employe.telephone"
                :can-pay="can_payer && commission_summary.reste_a_payer > 0"
                :pay-label="`Payer ${formatGNF(commission_summary.reste_a_payer)}`"
                @pay="showPaiementDialog = true"
            />

            <CommissionSummaryCards
                :summary="commission_summary"
                frais-label="Déductions"
            />

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
                    <div class="border-b px-4 py-3">
                        <div class="flex items-center gap-2">
                            <h2
                                class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                Détail par période de paie
                            </h2>
                            <span
                                class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground tabular-nums"
                                >{{ commission_details.length }}</span
                            >
                        </div>
                    </div>
                    <CommissionDetailTable
                        :rows="commission_details"
                        empty-message="Aucune période de paie pour ce salarié."
                    />
                </div>
            </template>

            <template v-if="activeTab === 'depenses'">
                <div
                    class="overflow-hidden rounded-xl border bg-card shadow-sm"
                >
                    <div class="border-b px-4 py-3">
                        <h2 class="text-sm font-semibold">
                            Dépenses imputées à ce salarié
                        </h2>
                    </div>
                    <CommissionExpensesTable
                        :rows="expenses"
                        empty-message="Aucune dépense imputée à ce salarié."
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
                        auditable-type="App\Models\Employe"
                        :auditable-id="employe.id"
                        module="salaires"
                    />
                </div>
            </template>
        </div>
    </AppLayout>

    <CommissionPaymentDialog
        v-model:visible="showPaiementDialog"
        :beneficiaire-nom="employe.nom"
        :solde-a-payer="commission_summary.reste_a_payer"
        :modes-paiement="modes_paiement"
        :payment-route="`/backoffice/comptabilite/salaires/employes/${employe.id}/paiements`"
    />
</template>
