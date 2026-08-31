<script setup lang="ts">
import CommissionDetailHeader from '@/components/commission/CommissionDetailHeader.vue';
import CommissionDetailTabs from '@/components/commission/CommissionDetailTabs.vue';
import CommissionExpensesTable from '@/components/commission/CommissionExpensesTable.vue';
import CommissionPaymentsTable from '@/components/commission/CommissionPaymentsTable.vue';
import CommissionSummaryCards from '@/components/commission/CommissionSummaryCards.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import type {
    CommissionDetailTab,
    CommissionExpenseRow,
    CommissionPaymentRow,
    CommissionSummary,
    ModePaiementOption,
} from '@/types/commission';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { HandCoins, Plus, ShieldCheck } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Select from 'primevue/select';
import { computed, ref } from 'vue';

interface CashbackTransaction {
    id: string;
    reference: string | null;
    date: string | null;
    montant: number;
    montant_verse: number;
    montant_restant: number;
    statut: string;
    statut_label: string;
    note: string | null;
    valide_le: string | null;
}

const props = defineProps<{
    client: { id: string; nom: string; telephone: string | null };
    commission_summary: CommissionSummary;
    transactions: CashbackTransaction[];
    expenses: CommissionExpenseRow[];
    payments: CommissionPaymentRow[];
    can_valider: boolean;
    modes_paiement: ModePaiementOption[];
    montant_disponible: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité' },
    {
        title: 'Cashback clients',
        href: '/backoffice/comptabilite/commissions/cashback',
    },
    { title: props.client.nom, href: '' },
];

const activeTab = ref<CommissionDetailTab>('informations');
const validerTarget = ref<CashbackTransaction | null>(null);
const verserTarget = ref<CashbackTransaction | null>(null);

const validerForm = useForm({ note: '' });
const verserForm = useForm({
    montant: 0,
    mode_paiement: '',
    date_versement: new Date().toISOString().slice(0, 10),
    note: '',
});

const maximumVersement = computed(() =>
    verserTarget.value
        ? Math.min(verserTarget.value.montant_restant, props.montant_disponible)
        : 0,
);
const verserErrors = computed(
    () => verserForm.errors as Record<string, string | undefined>,
);

function openValider(transaction: CashbackTransaction) {
    validerForm.reset();
    validerTarget.value = transaction;
}

function submitValider() {
    if (!validerTarget.value) return;
    validerForm.patch(
        `/backoffice/cashback/${validerTarget.value.id}/valider`,
        {
            preserveScroll: true,
            onSuccess: () => (validerTarget.value = null),
        },
    );
}

function openVerser(transaction: CashbackTransaction) {
    verserForm.reset();
    verserTarget.value = transaction;
    verserForm.montant = Math.min(
        transaction.montant_restant,
        props.montant_disponible,
    );
}

function submitVerser() {
    if (!verserTarget.value) return;
    verserForm.patch(`/backoffice/cashback/${verserTarget.value.id}/verser`, {
        preserveScroll: true,
        onSuccess: () => (verserTarget.value = null),
    });
}
</script>

<template>
    <Head :title="`Cashback — ${client.nom}`" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-5xl space-y-6 px-4 py-6 sm:px-6">
            <CommissionDetailHeader
                back-href="/backoffice/comptabilite/commissions/cashback"
                eyebrow="Client"
                :title="client.nom"
                :telephone="client.telephone"
                :can-pay="false"
                pay-label=""
            />

            <CommissionSummaryCards
                :summary="commission_summary"
                frais-label="Dépenses client"
            />

            <CommissionDetailTabs
                v-model="activeTab"
                :counts="{
                    depenses: expenses.length,
                    paiements: payments.length,
                }"
            />

            <div
                v-if="activeTab === 'informations'"
                class="overflow-hidden rounded-xl border bg-card shadow-sm"
            >
                <div
                    class="flex items-center justify-between border-b px-4 py-3"
                >
                    <h2 class="text-sm font-semibold">Gains cashback</h2>
                    <span class="text-xs text-muted-foreground">
                        {{ transactions.length }} gain{{
                            transactions.length !== 1 ? 's' : ''
                        }}
                    </span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[860px] text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40">
                                <th class="px-4 py-3 text-left font-medium">
                                    Date
                                </th>
                                <th class="px-4 py-3 text-left font-medium">
                                    Vente
                                </th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Gagné
                                </th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Versé
                                </th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Restant brut
                                </th>
                                <th class="px-4 py-3 text-left font-medium">
                                    Statut
                                </th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Action
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="transaction in transactions"
                                :key="transaction.id"
                            >
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ transaction.date ?? '—' }}
                                </td>
                                <td class="px-4 py-3 font-medium">
                                    {{ transaction.reference ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ formatGNF(transaction.montant) }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ formatGNF(transaction.montant_verse) }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right font-medium tabular-nums"
                                >
                                    {{ formatGNF(transaction.montant_restant) }}
                                </td>
                                <td class="px-4 py-3">
                                    <StatusDot
                                        :status="transaction.statut"
                                        :label="transaction.statut_label"
                                    />
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Button
                                        v-if="
                                            transaction.statut ===
                                                'en_attente' && can_valider
                                        "
                                        size="sm"
                                        variant="outline"
                                        @click="openValider(transaction)"
                                    >
                                        <ShieldCheck class="mr-1.5 h-4 w-4" />
                                        Valider
                                    </Button>
                                    <Button
                                        v-else-if="
                                            ['valide', 'partiel'].includes(
                                                transaction.statut,
                                            ) && montant_disponible > 0
                                        "
                                        size="sm"
                                        @click="openVerser(transaction)"
                                    >
                                        <HandCoins class="mr-1.5 h-4 w-4" />
                                        Verser
                                    </Button>
                                    <span
                                        v-else
                                        class="text-xs text-muted-foreground"
                                        >—</span
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div
                v-if="activeTab === 'depenses'"
                class="overflow-hidden rounded-xl border bg-card shadow-sm"
            >
                <div
                    class="flex items-center justify-between border-b px-4 py-3"
                >
                    <h2 class="text-sm font-semibold">Dépenses client</h2>
                    <Button as-child size="sm" variant="outline">
                        <Link
                            :href="`/backoffice/depenses/create?beneficiaire_type=client&beneficiaire_id=${client.id}`"
                        >
                            <Plus class="mr-1.5 h-4 w-4" />
                            Nouvelle dépense
                        </Link>
                    </Button>
                </div>
                <CommissionExpensesTable :rows="expenses" />
            </div>

            <div
                v-if="activeTab === 'paiements'"
                class="overflow-hidden rounded-xl border bg-card shadow-sm"
            >
                <div class="border-b px-4 py-3">
                    <h2 class="text-sm font-semibold">
                        Versements enregistrés
                    </h2>
                </div>
                <CommissionPaymentsTable
                    :rows="payments"
                    :modes-paiement="modes_paiement"
                />
            </div>

            <div
                v-if="activeTab === 'historique'"
                class="overflow-hidden rounded-xl border bg-card shadow-sm"
            >
                <div class="border-b px-4 py-3">
                    <h2 class="text-sm font-semibold">
                        Historique des validations
                    </h2>
                </div>
                <div
                    v-if="
                        transactions.some(
                            (transaction) =>
                                transaction.note || transaction.valide_le,
                        )
                    "
                    class="divide-y"
                >
                    <div
                        v-for="transaction in transactions.filter(
                            (item) => item.note || item.valide_le,
                        )"
                        :key="transaction.id"
                        class="px-4 py-3"
                    >
                        <p class="text-sm font-medium">
                            {{
                                transaction.reference ??
                                `Gain #${transaction.id.slice(-6)}`
                            }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ transaction.valide_le ?? transaction.date }}
                            <template v-if="transaction.note">
                                · {{ transaction.note }}</template
                            >
                        </p>
                    </div>
                </div>
                <p
                    v-else
                    class="px-4 py-10 text-center text-sm text-muted-foreground"
                >
                    Aucun historique de validation.
                </p>
            </div>
        </div>
    </AppLayout>

    <Dialog
        :visible="validerTarget !== null"
        modal
        header="Valider le cashback"
        :style="{ width: '440px' }"
        @update:visible="!$event && (validerTarget = null)"
    >
        <div v-if="validerTarget" class="space-y-4">
            <div class="rounded-lg border bg-muted/30 px-4 py-3">
                <p class="text-xs text-muted-foreground">Montant à valider</p>
                <p class="text-lg font-bold tabular-nums">
                    {{ formatGNF(validerTarget.montant) }}
                </p>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium"
                    >Note (facultative)</label
                >
                <textarea
                    v-model="validerForm.note"
                    rows="3"
                    class="w-full rounded-md border bg-background px-3 py-2 text-sm"
                />
            </div>
        </div>
        <template #footer>
            <Button variant="outline" @click="validerTarget = null"
                >Annuler</Button
            >
            <Button :disabled="validerForm.processing" @click="submitValider"
                >Confirmer</Button
            >
        </template>
    </Dialog>

    <Dialog
        :visible="verserTarget !== null"
        modal
        header="Verser le cashback"
        :style="{ width: '440px' }"
        @update:visible="!$event && (verserTarget = null)"
    >
        <div v-if="verserTarget" class="space-y-4">
            <div class="rounded-lg border bg-muted/30 px-4 py-3 text-sm">
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground"
                        >Net disponible client</span
                    >
                    <strong>{{ formatGNF(montant_disponible) }}</strong>
                </div>
                <div class="mt-1 flex justify-between gap-4">
                    <span class="text-muted-foreground"
                        >Maximum sur ce gain</span
                    >
                    <strong>{{ formatGNF(maximumVersement) }}</strong>
                </div>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium"
                    >Montant (GNF)</label
                >
                <input
                    v-model.number="verserForm.montant"
                    type="number"
                    min="1"
                    :max="maximumVersement"
                    class="h-9 w-full rounded-md border bg-background px-3 text-sm"
                />
                <p
                    v-if="verserForm.errors.montant"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ verserForm.errors.montant }}
                </p>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium"
                    >Mode de paiement</label
                >
                <Select
                    v-model="verserForm.mode_paiement"
                    :options="modes_paiement"
                    option-label="label"
                    option-value="value"
                    placeholder="Choisir"
                    class="w-full"
                />
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium"
                    >Date du versement</label
                >
                <input
                    v-model="verserForm.date_versement"
                    type="date"
                    class="h-9 w-full rounded-md border bg-background px-3 text-sm"
                />
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium"
                    >Note (facultative)</label
                >
                <textarea
                    v-model="verserForm.note"
                    rows="2"
                    class="w-full rounded-md border bg-background px-3 py-2 text-sm"
                />
            </div>
            <p
                v-if="verserErrors.comptabilisation"
                class="text-xs text-destructive"
            >
                {{ verserErrors.comptabilisation }}
            </p>
        </div>
        <template #footer>
            <Button variant="outline" @click="verserTarget = null"
                >Annuler</Button
            >
            <Button
                :disabled="verserForm.processing || maximumVersement <= 0"
                @click="submitVerser"
            >
                Confirmer le versement
            </Button>
        </template>
    </Dialog>
</template>
