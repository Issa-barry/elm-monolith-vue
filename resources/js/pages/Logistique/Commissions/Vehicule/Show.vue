<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    HandCoins,
    History,
    Truck,
    User,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { computed, reactive, ref } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────

interface SoldeRow {
    id: number;
    type: 'livreur' | 'proprietaire';
    nom: string;
    pending: number;
    available: number;
    paid: number;
}

interface PaymentRow {
    id: number;
    beneficiary_type: string;
    beneficiary_nom: string;
    montant: number;
    mode_paiement: string;
    note: string | null;
    paid_at: string | null;
    created_by: string | null;
}

interface ModePaiement { value: string; label: string }

// ── Props ─────────────────────────────────────────────────────────────────────

const props = defineProps<{
    vehicule: { id: number; nom: string; immatriculation: string | null };
    livreurs: SoldeRow[];
    proprietaires: SoldeRow[];
    payments: PaymentRow[];
    modes_paiement: ModePaiement[];
    can_payer: boolean;
}>();

// ── Breadcrumbs ───────────────────────────────────────────────────────────────

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Commissions', href: '/logistique/commissions' },
    { title: props.vehicule.nom, href: '' },
];

// ── Onglets ───────────────────────────────────────────────────────────────────

const activeTab = ref<'livreurs' | 'proprietaires'>(
    props.livreurs.length > 0 ? 'livreurs' : 'proprietaires',
);

// ── Agrégats ──────────────────────────────────────────────────────────────────

const sum = (rows: SoldeRow[], key: keyof SoldeRow) =>
    rows.reduce((s, r) => s + (r[key] as number), 0);

const livreurTotals = computed(() => ({
    pending:   sum(props.livreurs, 'pending'),
    available: sum(props.livreurs, 'available'),
    paid:      sum(props.livreurs, 'paid'),
}));
const propriTotals = computed(() => ({
    pending:   sum(props.proprietaires, 'pending'),
    available: sum(props.proprietaires, 'available'),
    paid:      sum(props.proprietaires, 'paid'),
}));

const grandPending   = computed(() => livreurTotals.value.pending   + propriTotals.value.pending);
const grandAvailable = computed(() => livreurTotals.value.available + propriTotals.value.available);
const grandPaid      = computed(() => livreurTotals.value.paid      + propriTotals.value.paid);

// ── Dialog paiement ───────────────────────────────────────────────────────────

const showPaiementDialog = ref(false);
const dialogRow = ref<SoldeRow | null>(null);

interface PaiementForm {
    montant: number | null;
    mode_paiement: string;
    note: string;
    processing: boolean;
    errors: Record<string, string>;
}

const paiementForm = reactive<PaiementForm>({
    montant: null,
    mode_paiement: 'especes',
    note: '',
    processing: false,
    errors: {},
});

function openPaiement(row: SoldeRow) {
    dialogRow.value = row;
    paiementForm.montant = row.available > 0 ? row.available : null;
    paiementForm.mode_paiement = 'especes';
    paiementForm.note = '';
    paiementForm.processing = false;
    paiementForm.errors = {};
    showPaiementDialog.value = true;
}

function submitPaiement() {
    const row = dialogRow.value;
    if (!row || !paiementForm.montant || paiementForm.montant <= 0) return;
    paiementForm.processing = true;
    paiementForm.errors = {};

    router.post(
        `/logistique/commissions/vehicules/${props.vehicule.id}/paiements`,
        {
            beneficiary_type: row.type,
            beneficiary_id:   row.id,
            montant:          paiementForm.montant,
            mode_paiement:    paiementForm.mode_paiement,
            note:             paiementForm.note || null,
        },
        {
            preserveScroll: true,
            onSuccess: () => { showPaiementDialog.value = false; },
            onError:   (e) => { paiementForm.errors = e as Record<string, string>; },
            onFinish:  () => { paiementForm.processing = false; },
        },
    );
}

// ── Dialog historique ─────────────────────────────────────────────────────────

const showHistoriqueDialog = ref(false);
const historiqueFiltre = ref<SoldeRow | null>(null);

const historiqueRows = computed(() =>
    historiqueFiltre.value
        ? props.payments.filter(
              (p) =>
                  p.beneficiary_type === historiqueFiltre.value!.type &&
                  p.beneficiary_nom  === historiqueFiltre.value!.nom,
          )
        : props.payments,
);

function openHistorique(row: SoldeRow | null = null) {
    historiqueFiltre.value = row;
    showHistoriqueDialog.value = true;
}

function releveUrl(type: 'livreur' | 'proprietaire', id: number): string {
    return `/logistique/commissions/vehicules/${props.vehicule.id}/beneficiaires/${type}/${id}`;
}

// ── Formatage ─────────────────────────────────────────────────────────────────

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

function formatMode(mode: string): string {
    return props.modes_paiement.find((m) => m.value === mode)?.label ?? mode;
}
</script>

<template>
    <Head :title="`Commissions — ${vehicule.nom}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl space-y-6 px-4 py-6 sm:px-6">

            <!-- ── En-tête ──────────────────────────────────────────────────── -->
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-center gap-3">
                    <Link href="/logistique/commissions"
                          class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground hover:bg-muted/80">
                        <ArrowLeft class="h-4 w-4" />
                    </Link>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-muted-foreground">Véhicule</p>
                        <p class="mt-0.5 text-xl font-semibold">
                            {{ vehicule.nom }}
                            <span v-if="vehicule.immatriculation" class="ml-2 font-mono text-sm font-normal text-muted-foreground">
                                ({{ vehicule.immatriculation }})
                            </span>
                        </p>
                    </div>
                </div>
                <Button variant="outline" size="sm" @click="openHistorique()">
                    <History class="mr-1.5 h-3.5 w-3.5" />
                    Historique
                    <span class="ml-1 rounded-full bg-muted px-1.5 py-0.5 text-[10px] tabular-nums">{{ payments.length }}</span>
                </Button>
            </div>

            <!-- ── KPIs ──────────────────────────────────────────────────────── -->
            <div class="grid grid-cols-3 gap-3">
                <div class="rounded-lg border bg-card px-4 py-3 text-center">
                    <p class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">En attente</p>
                    <p class="mt-0.5 font-semibold tabular-nums text-zinc-500 dark:text-zinc-400">{{ formatGNF(grandPending) }}</p>
                </div>
                <div class="rounded-lg border bg-card px-4 py-3 text-center"
                     :class="grandAvailable > 0 ? 'border-amber-200 dark:border-amber-900' : ''">
                    <p class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Disponible</p>
                    <p class="mt-0.5 font-semibold tabular-nums"
                       :class="grandAvailable > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-foreground'">
                        {{ formatGNF(grandAvailable) }}
                    </p>
                </div>
                <div class="rounded-lg border bg-card px-4 py-3 text-center">
                    <p class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Versé</p>
                    <p class="mt-0.5 font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">{{ formatGNF(grandPaid) }}</p>
                </div>
            </div>

            <!-- ── Onglets ────────────────────────────────────────────────────── -->
            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <div class="flex items-center gap-1 border-b px-4 py-2">
                    <button
                        class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
                        :class="activeTab === 'livreurs' ? 'bg-primary/10 text-primary' : 'text-muted-foreground hover:bg-muted'"
                        :disabled="livreurs.length === 0"
                        @click="activeTab = 'livreurs'"
                    >
                        <Truck class="h-3.5 w-3.5" />
                        Livreurs
                        <span class="rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium">{{ livreurs.length }}</span>
                    </button>
                    <button
                        class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
                        :class="activeTab === 'proprietaires' ? 'bg-primary/10 text-primary' : 'text-muted-foreground hover:bg-muted'"
                        :disabled="proprietaires.length === 0"
                        @click="activeTab = 'proprietaires'"
                    >
                        <User class="h-3.5 w-3.5" />
                        Propriétaires
                        <span class="rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium">{{ proprietaires.length }}</span>
                    </button>
                </div>

                <!-- ── Livreurs ─────────────────────────────────────────────── -->
                <div v-if="activeTab === 'livreurs'" class="overflow-x-auto">
                    <table v-if="livreurs.length > 0" class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40">
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Livreur</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">En attente</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Disponible</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Versé</th>
                                <th class="px-4 py-3 text-center font-medium text-muted-foreground">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="row in livreurs" :key="row.id" class="transition-colors hover:bg-muted/10">
                                <td class="px-4 py-3 font-medium">{{ row.nom }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-muted-foreground">{{ formatGNF(row.pending) }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums"
                                    :class="row.available > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'">
                                    {{ formatGNF(row.available) }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-emerald-600 dark:text-emerald-400">{{ formatGNF(row.paid) }}</td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <Link :href="releveUrl('livreur', row.id)" class="text-xs text-primary hover:underline">
                                            Relevé
                                        </Link>
                                        <Button v-if="can_payer && row.available > 0" size="sm" class="h-7 px-2.5" @click="openPaiement(row)">
                                            Payer
                                        </Button>
                                        <Button v-if="row.paid > 0" variant="ghost" size="sm" class="h-7 px-2.5" @click="openHistorique(row)">
                                            <History class="h-3.5 w-3.5" />
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 bg-muted/20 font-semibold">
                                <td class="px-4 py-2.5 text-xs font-bold uppercase text-muted-foreground">Total</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{{ formatGNF(livreurTotals.pending) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums"
                                    :class="livreurTotals.available > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'">
                                    {{ formatGNF(livreurTotals.available) }}
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-emerald-600 dark:text-emerald-400">{{ formatGNF(livreurTotals.paid) }}</td>
                                <td />
                            </tr>
                        </tfoot>
                    </table>
                    <div v-else class="py-10 text-center text-sm text-muted-foreground">Aucun livreur.</div>
                </div>

                <!-- ── Propriétaires ────────────────────────────────────────── -->
                <div v-if="activeTab === 'proprietaires'" class="overflow-x-auto">
                    <table v-if="proprietaires.length > 0" class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40">
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Propriétaire</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">En attente</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Disponible</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Versé</th>
                                <th class="px-4 py-3 text-center font-medium text-muted-foreground">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="row in proprietaires" :key="row.id" class="transition-colors hover:bg-muted/10">
                                <td class="px-4 py-3 font-medium">{{ row.nom }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-muted-foreground">{{ formatGNF(row.pending) }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums"
                                    :class="row.available > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'">
                                    {{ formatGNF(row.available) }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-emerald-600 dark:text-emerald-400">{{ formatGNF(row.paid) }}</td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <Link :href="releveUrl('proprietaire', row.id)" class="text-xs text-primary hover:underline">
                                            Relevé
                                        </Link>
                                        <Button v-if="can_payer && row.available > 0" size="sm" class="h-7 px-2.5" @click="openPaiement(row)">
                                            Payer
                                        </Button>
                                        <Button v-if="row.paid > 0" variant="ghost" size="sm" class="h-7 px-2.5" @click="openHistorique(row)">
                                            <History class="h-3.5 w-3.5" />
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 bg-muted/20 font-semibold">
                                <td class="px-4 py-2.5 text-xs font-bold uppercase text-muted-foreground">Total</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{{ formatGNF(propriTotals.pending) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums"
                                    :class="propriTotals.available > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'">
                                    {{ formatGNF(propriTotals.available) }}
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-emerald-600 dark:text-emerald-400">{{ formatGNF(propriTotals.paid) }}</td>
                                <td />
                            </tr>
                        </tfoot>
                    </table>
                    <div v-else class="py-10 text-center text-sm text-muted-foreground">Aucun propriétaire.</div>
                </div>
            </div>

        </div>

        <!-- ── Dialog : Paiement ─────────────────────────────────────────────── -->
        <Dialog
            v-model:visible="showPaiementDialog"
            modal
            :header="`Payer — ${dialogRow?.nom ?? ''}`"
            :style="{ width: '420px' }"
            :draggable="false"
        >
            <div class="space-y-4 py-2">
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300">
                    Solde disponible : <strong>{{ formatGNF(dialogRow?.available ?? 0) }}</strong>
                </div>
                <div>
                    <Label class="mb-1.5 block text-sm">Montant (GNF)</Label>
                    <InputNumber
                        v-model="paiementForm.montant"
                        :min="1"
                        :max="dialogRow?.available ?? undefined"
                        class="w-full" input-class="w-full"
                    />
                    <p v-if="paiementForm.errors.montant" class="mt-1 text-xs text-destructive">
                        {{ paiementForm.errors.montant }}
                    </p>
                </div>
                <div>
                    <Label class="mb-1.5 block text-sm">Mode de paiement</Label>
                    <Dropdown
                        v-model="paiementForm.mode_paiement"
                        :options="modes_paiement" option-label="label" option-value="value" class="w-full"
                    />
                </div>
                <div>
                    <Label class="mb-1.5 block text-sm">Note (optionnel)</Label>
                    <InputText v-model="paiementForm.note" class="w-full" placeholder="Remarque…" />
                </div>
            </div>
            <template #footer>
                <Button variant="outline" :disabled="paiementForm.processing" @click="showPaiementDialog = false">Annuler</Button>
                <Button :disabled="paiementForm.processing || !paiementForm.montant" @click="submitPaiement">
                    <HandCoins v-if="!paiementForm.processing" class="mr-1.5 h-4 w-4" />
                    <span v-else class="mr-2 inline-block h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent" />
                    {{ paiementForm.processing ? 'Enregistrement…' : 'Enregistrer le paiement' }}
                </Button>
            </template>
        </Dialog>

        <!-- ── Dialog : Historique ───────────────────────────────────────────── -->
        <Dialog
            v-model:visible="showHistoriqueDialog"
            modal
            :dismissable-mask="true"
            :header="historiqueFiltre ? `Historique — ${historiqueFiltre.nom}` : `Tous les paiements — ${vehicule.nom}`"
            :style="{ width: 'min(820px, 96vw)' }"
            :draggable="false"
        >
            <div v-if="historiqueRows.length > 0" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40">
                            <th class="px-3 py-2.5 text-left font-medium text-muted-foreground">Bénéficiaire</th>
                            <th class="px-3 py-2.5 text-left font-medium text-muted-foreground">Date</th>
                            <th class="px-3 py-2.5 text-left font-medium text-muted-foreground">Mode</th>
                            <th class="px-3 py-2.5 text-right font-medium text-muted-foreground">Montant</th>
                            <th class="px-3 py-2.5 text-left font-medium text-muted-foreground">Note</th>
                            <th class="px-3 py-2.5 text-left font-medium text-muted-foreground">Par</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="p in historiqueRows" :key="p.id" class="hover:bg-muted/10">
                            <td class="px-3 py-2.5 font-medium">{{ p.beneficiary_nom }}</td>
                            <td class="px-3 py-2.5 tabular-nums">{{ p.paid_at ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-muted-foreground">{{ formatMode(p.mode_paiement) }}</td>
                            <td class="px-3 py-2.5 text-right font-semibold tabular-nums">{{ formatGNF(p.montant) }}</td>
                            <td class="px-3 py-2.5 text-muted-foreground">{{ p.note || '—' }}</td>
                            <td class="px-3 py-2.5 text-muted-foreground">{{ p.created_by ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="py-4 text-center text-sm text-muted-foreground">Aucun paiement.</p>
        </Dialog>

    </AppLayout>
</template>
