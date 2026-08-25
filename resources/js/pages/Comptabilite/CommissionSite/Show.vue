<script setup lang="ts">
import CommissionDetailHeader from '@/components/commission/CommissionDetailHeader.vue';
import CommissionDetailTable from '@/components/commission/CommissionDetailTable.vue';
import CommissionDetailTabs from '@/components/commission/CommissionDetailTabs.vue';
import CommissionExpensesTable from '@/components/commission/CommissionExpensesTable.vue';
import CommissionHistoryTable from '@/components/commission/CommissionHistoryTable.vue';
import CommissionPaymentsTable from '@/components/commission/CommissionPaymentsTable.vue';
import CommissionPeriodSelect from '@/components/commission/CommissionPeriodSelect.vue';
import CommissionSummaryCards from '@/components/commission/CommissionSummaryCards.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import type {
    CommissionDetailRow,
    CommissionDetailTab,
    CommissionExpenseRow,
    CommissionGlobalFiltersValue,
    CommissionPaymentRow,
    CommissionSummary,
    ModePaiementOption,
    PeriodeOption,
} from '@/types/commission';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
    site: { id: string; nom: string; code: string | null; telephone: string | null };
    commission_summary: CommissionSummary;
    commission_details: CommissionDetailRow[];
    payments: CommissionPaymentRow[];
    expenses: CommissionExpenseRow[];
    modes_paiement: ModePaiementOption[];
    selected_periode: string;
    periodes_disponibles: PeriodeOption[];
    filters: CommissionGlobalFiltersValue;
    can_payer: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité' },
    {
        title: 'Commission sites',
        href: '/backoffice/comptabilite/commissions/sites',
    },
    { title: props.site.nom, href: '' },
];

const filters = ref<CommissionGlobalFiltersValue>({ ...props.filters });

function reload(next: string) {
    filters.value = { ...filters.value, periode: next };
    router.get(
        `/backoffice/comptabilite/commissions/sites/${props.site.id}`,
        { periode: next || undefined },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

const activePeriodeLabel = () =>
    props.periodes_disponibles.find((p) => p.code === filters.value.periode)
        ?.label ?? '';

const activeTab = ref<CommissionDetailTab>('informations');
</script>

<template>
    <Head :title="`Commission site — ${site.nom}`" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-5xl space-y-6 px-4 py-6 sm:px-6">
            <CommissionDetailHeader
                :back-href="'/backoffice/comptabilite/commissions/sites'"
                eyebrow="Site"
                :title="site.nom"
                :telephone="null"
                :active-filters-label="activePeriodeLabel()"
                :can-pay="false"
                pay-label=""
            />
            <p v-if="site.code" class="-mt-4 text-xs text-muted-foreground">
                Code : {{ site.code }}
            </p>

            <CommissionSummaryCards
                :summary="commission_summary"
                frais-label="Dépenses"
            />

            <div
                class="flex flex-col gap-2 rounded-xl border bg-card p-3 sm:flex-row sm:items-center sm:gap-3"
            >
                <div class="w-full sm:w-64">
                    <CommissionPeriodSelect
                        :model-value="filters.periode"
                        :periodes-disponibles="periodes_disponibles"
                        @update:model-value="reload"
                    />
                </div>
            </div>

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
                                class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground tabular-nums"
                                >{{ commission_details.length }}</span
                            >
                        </div>
                    </div>
                    <CommissionDetailTable :rows="commission_details" />
                </div>
            </template>

            <template v-if="activeTab === 'depenses'">
                <div
                    class="overflow-hidden rounded-xl border bg-card shadow-sm"
                >
                    <div class="border-b px-4 py-3">
                        <h2 class="text-sm font-semibold">Dépenses</h2>
                    </div>
                    <CommissionExpensesTable :rows="expenses" />
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
                    <CommissionHistoryTable
                        auditable-type="App\Models\Site"
                        :auditable-id="site.id"
                        module="commissions_sites"
                        :filters="filters"
                    />
                </div>
            </template>
        </div>
    </AppLayout>
</template>
