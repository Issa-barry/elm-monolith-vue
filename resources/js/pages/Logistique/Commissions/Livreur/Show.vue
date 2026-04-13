<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CalendarClock,
    CalendarDays,
    CheckCircle2,
    HandCoins,
    History,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import { reactive, ref } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────

interface PaymentDetail {
    paid_at: string | null;
    montant: number;
    mode_paiement: string | null;
}

interface PartRow {
    id: number;
    transfert_reference: string | null;
    taux_commission: number;
    montant_brut: number;
    montant_net: number;
    montant_verse: number;
    montant_restant: number;
    earned_at: string | null;
    unlock_at: string | null;
    periode: string | null;
    periode_label: string | null;
    statut: string | null;
    statut_label: string;
    statut_dot_class: string;
    payments: PaymentDetail[];
}

interface PaymentRow {
    id: number;
    montant: number;
    mode_paiement: string;
    note: string | null;
    paid_at: string | null;
    created_by: string | null;
}

interface ModePaiement {
    value: string;
    label: string;
}

interface PeriodeOption {
    code: string;
    label: string;
}

// ── Props ─────────────────────────────────────────────────────────────────────

const props = defineProps<{
    livreur: { id: number; nom: string };
    kpis: { pending: number; available: number; paid: number };
    parts: PartRow[];
    payments: PaymentRow[];
    modes_paiement: ModePaiement[];
    can_payer: boolean;
    periode_courante: string;
    periode_courante_label: string;
    selected_periode: string;
    periodes_disponibles: PeriodeOption[];
}>();

// ── Breadcrumbs ───────────────────────────────────────────────────────────────

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Commissions', href: '/logistique/commissions' },
    { title: props.livreur.nom, href: '' },
];

// ── Filtre période ────────────────────────────────────────────────────────────

const selectedPeriode = ref<string>(props.selected_periode);

const periodeOptions: PeriodeOption[] = [
    { code: '', label: 'Toutes les périodes' },
    ...props.periodes_disponibles,
];

function onPeriodeChange(value: string) {
    const params: Record<string, string> = {};
    if (value) params.periode = value;
    router.get(`/logistique/commissions/livreurs/${props.livreur.id}`, params, {
        preserveScroll: true,
        replace: true,
    });
}

// ── Dialog paiement ───────────────────────────────────────────────────────────

const showPaiementDialog = ref(false);

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

function openPaiement() {
    paiementForm.montant =
        props.kpis.available > 0 ? props.kpis.available : null;
    paiementForm.mode_paiement = 'especes';
    paiementForm.note = '';
    paiementForm.processing = false;
    paiementForm.errors = {};
    showPaiementDialog.value = true;
}

function submitPaiement() {
    if (!paiementForm.montant || paiementForm.montant <= 0) return;
    paiementForm.processing = true;
    paiementForm.errors = {};

    router.post(
        `/logistique/commissions/livreurs/${props.livreur.id}/paiements`,
        {
            montant: paiementForm.montant,
            mode_paiement: paiementForm.mode_paiement,
            note: paiementForm.note || null,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                showPaiementDialog.value = false;
            },
            onError: (e) => {
                paiementForm.errors = e as Record<string, string>;
            },
            onFinish: () => {
                paiementForm.processing = false;
            },
        },
    );
}

// ── Dialog historique ─────────────────────────────────────────────────────────

const showHistoriqueDialog = ref(false);

// ── Formatage ─────────────────────────────────────────────────────────────────

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

function formatMode(mode: string): string {
    return props.modes_paiement.find((m) => m.value === mode)?.label ?? mode;
}
</script>

<template>
    <Head :title="`Commissions — ${livreur.nom}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-5xl space-y-6 px-4 py-6 sm:px-6">
            <!-- ── En-tête ──────────────────────────────────────────────────── -->
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-center gap-3">
                    <Link
                        href="/logistique/commissions"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground hover:bg-muted/80"
                    >
                        <ArrowLeft class="h-4 w-4" />
                    </Link>
                    <div>
                        <p
                            class="text-xs font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                        >
                            Livreur
                        </p>
                        <p class="mt-0.5 text-xl font-semibold">
                            {{ livreur.nom }}
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button
                        v-if="payments.length > 0"
                        variant="outline"
                        size="sm"
                        @click="showHistoriqueDialog = true"
                    >
                        <History class="mr-1.5 h-3.5 w-3.5" />
                        Historique
                        <span
                            class="ml-1 rounded-full bg-muted px-1.5 py-0.5 text-[10px] tabular-nums"
                            >{{ payments.length }}</span
                        >
                    </Button>
                    <Button
                        v-if="can_payer && kpis.available > 0"
                        size="sm"
                        @click="openPaiement"
                    >
                        <HandCoins class="mr-1.5 h-4 w-4" />
                        Payer {{ formatGNF(kpis.available) }}
                    </Button>
                </div>
            </div>

            <!-- ── Badge période courante ─────────────────────────────────────── -->
            <div
                class="flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 dark:border-blue-800 dark:bg-blue-950/30"
            >
                <CalendarDays
                    class="h-4 w-4 shrink-0 text-blue-600 dark:text-blue-400"
                />
                <span class="text-sm text-blue-800 dark:text-blue-300">
                    Période courante :
                    <strong>{{ periode_courante_label }}</strong>
                </span>
            </div>

            <!-- ── KPIs (totaux toutes périodes) ─────────────────────────────── -->
            <div class="grid grid-cols-3 gap-3">
                <div class="rounded-lg border bg-card px-4 py-3 text-center">
                    <p
                        class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        En attente
                    </p>
                    <p
                        class="mt-0.5 font-semibold text-zinc-500 tabular-nums dark:text-zinc-400"
                    >
                        {{ formatGNF(kpis.pending) }}
                    </p>
                </div>
                <div
                    class="rounded-lg border bg-card px-4 py-3 text-center"
                    :class="
                        kpis.available > 0
                            ? 'border-amber-200 dark:border-amber-900'
                            : ''
                    "
                >
                    <p
                        class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        Disponible
                    </p>
                    <p
                        class="mt-0.5 font-semibold tabular-nums"
                        :class="
                            kpis.available > 0
                                ? 'text-amber-600 dark:text-amber-400'
                                : 'text-foreground'
                        "
                    >
                        {{ formatGNF(kpis.available) }}
                    </p>
                </div>
                <div class="rounded-lg border bg-card px-4 py-3 text-center">
                    <p
                        class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        Versé
                    </p>
                    <p
                        class="mt-0.5 font-semibold text-emerald-600 tabular-nums dark:text-emerald-400"
                    >
                        {{ formatGNF(kpis.paid) }}
                    </p>
                </div>
            </div>

            <!-- ── Relevé par transfert ───────────────────────────────────────── -->
            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <!-- En-tête section + filtre période -->
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5"
                >
                    <div class="flex items-center gap-2">
                        <h2
                            class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Détail par transfert
                        </h2>
                        <span
                            class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground tabular-nums"
                            >{{ parts.length }}</span
                        >
                        <!-- Badge période filtrée -->
                        <span
                            v-if="selected_periode"
                            class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/40 dark:text-blue-300"
                        >
                            <CalendarDays class="h-3 w-3" />
                            {{
                                periodes_disponibles.find(
                                    (p) => p.code === selected_periode,
                                )?.label ?? selected_periode
                            }}
                        </span>
                    </div>
                    <!-- Filtre période -->
                    <Dropdown
                        v-model="selectedPeriode"
                        :options="periodeOptions"
                        option-label="label"
                        option-value="code"
                        placeholder="Toutes les périodes"
                        class="w-52 text-sm"
                        @change="onPeriodeChange(selectedPeriode)"
                    />
                </div>

                <div v-if="parts.length > 0" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40">
                                <th
                                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                                >
                                    Transfert
                                </th>
                                <th
                                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                                >
                                    Période
                                </th>
                                <th
                                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                                >
                                    Acquis le
                                </th>
                                <th
                                    class="px-4 py-3 text-right font-medium text-muted-foreground"
                                >
                                    Net
                                </th>
                                <th
                                    class="px-4 py-3 text-right font-medium text-muted-foreground"
                                >
                                    Versé
                                </th>
                                <th
                                    class="px-4 py-3 text-right font-medium text-muted-foreground"
                                >
                                    Restant
                                </th>
                                <th
                                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                                >
                                    Statut
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="part in parts" :key="part.id">
                                <!-- Ligne principale -->
                                <tr
                                    class="border-b transition-colors hover:bg-muted/10"
                                >
                                    <td
                                        class="px-4 py-3 font-mono text-sm font-semibold text-primary"
                                    >
                                        {{ part.transfert_reference ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span
                                            v-if="part.periode"
                                            class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-medium text-blue-700 dark:bg-blue-900/40 dark:text-blue-300"
                                        >
                                            <CalendarDays class="h-3 w-3" />
                                            {{ part.periode }}
                                        </span>
                                        <span
                                            v-else
                                            class="text-muted-foreground"
                                            >—</span
                                        >
                                    </td>
                                    <td
                                        class="px-4 py-3 text-muted-foreground tabular-nums"
                                    >
                                        <div class="flex items-center gap-1.5">
                                            <CalendarClock
                                                class="h-3.5 w-3.5 shrink-0 text-muted-foreground"
                                            />
                                            {{ part.earned_at ?? '—' }}
                                        </div>
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right font-semibold tabular-nums"
                                    >
                                        {{ formatGNF(part.montant_net) }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right text-emerald-600 tabular-nums dark:text-emerald-400"
                                    >
                                        {{ formatGNF(part.montant_verse) }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right font-semibold tabular-nums"
                                        :class="
                                            part.montant_restant > 0
                                                ? 'text-amber-600 dark:text-amber-400'
                                                : 'text-muted-foreground'
                                        "
                                    >
                                        {{ formatGNF(part.montant_restant) }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <StatusDot
                                            :label="part.statut_label"
                                            :dot-class="part.statut_dot_class"
                                            class="text-xs text-muted-foreground"
                                        />
                                    </td>
                                </tr>
                                <!-- Sous-lignes : paiements alloués -->
                                <tr
                                    v-for="(pmt, pi) in part.payments"
                                    :key="`pmt-${part.id}-${pi}`"
                                    class="border-b bg-emerald-50/40 text-xs dark:bg-emerald-950/10"
                                >
                                    <td
                                        class="py-1.5 pr-4 pl-8 text-muted-foreground italic"
                                        colspan="3"
                                    >
                                        <CheckCircle2
                                            class="mr-1 inline-block h-3 w-3 text-emerald-500"
                                        />
                                        Paiement du {{ pmt.paid_at ?? '—' }}
                                        <span
                                            v-if="pmt.mode_paiement"
                                            class="ml-1"
                                            >({{
                                                formatMode(pmt.mode_paiement)
                                            }})</span
                                        >
                                    </td>
                                    <td
                                        colspan="3"
                                        class="py-1.5 pr-4 text-right font-semibold text-emerald-700 tabular-nums dark:text-emerald-400"
                                    >
                                        {{ formatGNF(pmt.montant) }}
                                    </td>
                                    <td />
                                </tr>
                            </template>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 bg-muted/20 font-semibold">
                                <td
                                    colspan="3"
                                    class="px-4 py-2.5 text-xs font-bold text-muted-foreground uppercase"
                                >
                                    Total
                                    <span
                                        v-if="selected_periode"
                                        class="ml-1 font-normal text-muted-foreground/70 normal-case"
                                    >
                                        ({{
                                            periodes_disponibles.find(
                                                (p) =>
                                                    p.code === selected_periode,
                                            )?.label ?? selected_periode
                                        }})
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums">
                                    {{
                                        formatGNF(
                                            parts.reduce(
                                                (s, p) => s + p.montant_net,
                                                0,
                                            ),
                                        )
                                    }}
                                </td>
                                <td
                                    class="px-4 py-2.5 text-right text-emerald-600 tabular-nums dark:text-emerald-400"
                                >
                                    {{
                                        formatGNF(
                                            parts.reduce(
                                                (s, p) => s + p.montant_verse,
                                                0,
                                            ),
                                        )
                                    }}
                                </td>
                                <td
                                    class="px-4 py-2.5 text-right tabular-nums"
                                    :class="
                                        parts.reduce(
                                            (s, p) => s + p.montant_restant,
                                            0,
                                        ) > 0
                                            ? 'text-amber-600 dark:text-amber-400'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    {{
                                        formatGNF(
                                            parts.reduce(
                                                (s, p) => s + p.montant_restant,
                                                0,
                                            ),
                                        )
                                    }}
                                </td>
                                <td />
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div
                    v-else
                    class="py-12 text-center text-sm text-muted-foreground"
                >
                    <span v-if="selected_periode">
                        Aucune commission pour la période
                        <strong>{{
                            periodes_disponibles.find(
                                (p) => p.code === selected_periode,
                            )?.label ?? selected_periode
                        }}</strong
                        >.
                    </span>
                    <span v-else>
                        Aucune commission enregistrée pour ce livreur.
                    </span>
                </div>
            </div>
        </div>

        <!-- ── Dialog : Paiement ─────────────────────────────────────────────── -->
        <Dialog
            v-model:visible="showPaiementDialog"
            modal
            :header="`Payer — ${livreur.nom}`"
            :style="{ width: '440px' }"
            :draggable="false"
        >
            <div class="space-y-4 py-2">
                <!-- Info période courante -->
                <div
                    class="flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-800 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-300"
                >
                    <CalendarDays class="h-4 w-4 shrink-0 text-blue-500" />
                    <span>
                        Période courante :
                        <strong>{{ periode_courante_label }}</strong>
                    </span>
                </div>
                <div
                    class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300"
                >
                    Solde disponible (toutes périodes) :
                    <strong>{{ formatGNF(kpis.available) }}</strong>
                </div>
                <div>
                    <Label class="mb-1.5 block text-sm">Montant (GNF)</Label>
                    <InputNumber
                        v-model="paiementForm.montant"
                        :min="1"
                        :max="kpis.available"
                        class="w-full"
                        input-class="w-full"
                    />
                    <p
                        v-if="paiementForm.errors.montant"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ paiementForm.errors.montant }}
                    </p>
                </div>
                <div>
                    <Label class="mb-1.5 block text-sm">Mode de paiement</Label>
                    <Dropdown
                        v-model="paiementForm.mode_paiement"
                        :options="modes_paiement"
                        option-label="label"
                        option-value="value"
                        class="w-full"
                    />
                </div>
                <!-- <div>
                    <Label class="mb-1.5 block text-sm">Note (optionnel)</Label>
                    <InputText
                        v-model="paiementForm.note"
                        class="w-full"
                        placeholder="Remarque…"
                    />
                </div> -->
            </div>
            <template #footer>
                <Button
                    variant="outline"
                    :disabled="paiementForm.processing"
                    @click="showPaiementDialog = false"
                    >Annuler</Button
                >
                <Button
                    :disabled="paiementForm.processing || !paiementForm.montant"
                    @click="submitPaiement"
                >
                    <HandCoins
                        v-if="!paiementForm.processing"
                        class="mr-1.5 h-4 w-4"
                    />
                    <span
                        v-else
                        class="mr-2 inline-block h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent"
                    />
                    {{
                        paiementForm.processing
                            ? 'Enregistrement…'
                            : 'Enregistrer le paiement'
                    }}
                </Button>
            </template>
        </Dialog>

        <!-- ── Dialog : Historique des paiements ────────────────────────────── -->
        <Dialog
            v-model:visible="showHistoriqueDialog"
            modal
            :dismissable-mask="true"
            :header="`Historique — ${livreur.nom}`"
            :style="{ width: 'min(760px, 96vw)' }"
            :draggable="false"
        >
            <div v-if="payments.length > 0" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40">
                            <th
                                class="px-3 py-2.5 text-left font-medium text-muted-foreground"
                            >
                                Date
                            </th>
                            <th
                                class="px-3 py-2.5 text-left font-medium text-muted-foreground"
                            >
                                Mode
                            </th>
                            <th
                                class="px-3 py-2.5 text-right font-medium text-muted-foreground"
                            >
                                Montant
                            </th>
                            <th
                                class="px-3 py-2.5 text-left font-medium text-muted-foreground"
                            >
                                Note
                            </th>
                            <th
                                class="px-3 py-2.5 text-left font-medium text-muted-foreground"
                            >
                                Par
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="p in payments"
                            :key="p.id"
                            class="hover:bg-muted/10"
                        >
                            <td class="px-3 py-2.5 tabular-nums">
                                {{ p.paid_at ?? '—' }}
                            </td>
                            <td class="px-3 py-2.5 text-muted-foreground">
                                {{ formatMode(p.mode_paiement) }}
                            </td>
                            <td
                                class="px-3 py-2.5 text-right font-semibold tabular-nums"
                            >
                                {{ formatGNF(p.montant) }}
                            </td>
                            <td class="px-3 py-2.5 text-muted-foreground">
                                {{ p.note || '—' }}
                            </td>
                            <td class="px-3 py-2.5 text-muted-foreground">
                                {{ p.created_by ?? '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="py-4 text-center text-sm text-muted-foreground">
                Aucun paiement enregistré.
            </p>
        </Dialog>
    </AppLayout>
</template>
