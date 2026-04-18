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
    HandCoins,
    History,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import { computed, reactive, ref } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────

interface PartRow {
    id: number;
    transfert_reference: string | null;
    montant_net: number;
    earned_at: string | null;
    periode: string | null;
    periode_label: string | null;
    statut: string | null;
    statut_label: string;
    statut_dot_class: string;
}

interface PeriodeStats {
    code: string;
    label: string;
    total_commission: number;
    total_verse: number;
    reste: number;
    statut: string;
    statut_label: string;
    statut_dot_class: string;
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
    livreur: { id: number; nom: string; telephone: string | null };
    kpis: { pending: number; available: number; paid: number };
    parts: PartRow[];
    periode_stats: PeriodeStats | null;
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

// ── Groupement par période (vue "toutes périodes") ────────────────────────────

interface PeriodeGroup {
    code: string;
    label: string;
    statut_label: string;
    statut_dot_class: string;
    total_net: number;
    parts: PartRow[];
}

function groupStatut(parts: PartRow[]): { label: string; dot: string } {
    if (parts.every((p) => p.statut === 'paid'))
        return { label: 'Soldée', dot: 'bg-emerald-500' };
    if (parts.every((p) => p.statut === 'pending'))
        return { label: 'En attente', dot: 'bg-zinc-400 dark:bg-zinc-500' };
    if (parts.every((p) => p.statut === 'available'))
        return { label: 'Non versée', dot: 'bg-amber-500' };
    return { label: 'Partiellement versée', dot: 'bg-blue-500' };
}

const partsGrouped = computed<PeriodeGroup[]>(() => {
    const map = new Map<string, PeriodeGroup>();
    for (const part of props.parts) {
        const key = part.periode ?? '__sans__';
        if (!map.has(key)) {
            map.set(key, {
                code: key,
                label: part.periode_label ?? part.periode ?? '—',
                statut_label: '',
                statut_dot_class: '',
                total_net: 0,
                parts: [],
            });
        }
        const g = map.get(key)!;
        g.parts.push(part);
        g.total_net += part.montant_net;
    }
    for (const g of map.values()) {
        const s = groupStatut(g.parts);
        g.statut_label = s.label;
        g.statut_dot_class = s.dot;
    }
    return Array.from(map.values());
});

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

function formatPhone(tel: string | null): string {
    if (!tel) return '';
    const digits = tel.replace(/\D/g, '');
    if (digits.startsWith('33') && digits.length === 11)
        return `+33 ${digits[2]} ${digits.slice(3, 5)} ${digits.slice(5, 7)} ${digits.slice(7, 9)} ${digits.slice(9, 11)}`;
    if (digits.startsWith('224') && digits.length === 12)
        return `+224 ${digits.slice(3, 6)} ${digits.slice(6, 9)} ${digits.slice(9, 12)}`;
    return tel;
}

function formatMode(mode: string): string {
    return props.modes_paiement.find((m) => m.value === mode)?.label ?? mode;
}
</script>

<template>
    <Head :title="`Commissions — ${livreur.nom}`" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- ── MOBILE STICKY HEADER ──────────────────────────────────────────── -->
        <div
            class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden"
        >
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link
                    href="/logistique/commissions"
                    class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95"
                >
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div class="text-center">
                    <p
                        class="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                    >
                        Livreur
                    </p>
                    <h1 class="text-[17px] leading-tight font-semibold">
                        {{ livreur.nom }}
                    </h1>
                    <p
                        v-if="livreur.telephone"
                        class="text-[11px] text-muted-foreground"
                    >
                        {{ formatPhone(livreur.telephone) }}
                    </p>
                </div>
                <div class="absolute right-4 flex items-center gap-1">
                    <Button
                        v-if="payments.length > 0"
                        variant="ghost"
                        size="icon"
                        class="relative h-9 w-9"
                        @click="showHistoriqueDialog = true"
                    >
                        <History class="h-5 w-5" />
                        <span
                            class="absolute top-0.5 right-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-primary text-[9px] font-bold text-primary-foreground"
                            >{{ payments.length }}</span
                        >
                    </Button>
                    <Button
                        v-if="can_payer && kpis.available > 0"
                        size="sm"
                        class="h-8 px-3 text-xs"
                        @click="openPaiement"
                    >
                        <HandCoins class="mr-1 h-3.5 w-3.5" />
                        Payer
                    </Button>
                </div>
            </div>
        </div>

        <div
            class="mx-auto w-full max-w-5xl space-y-4 px-4 py-4 sm:space-y-6 sm:px-6 sm:py-6"
        >
            <!-- ── En-tête desktop ────────────────────────────────────────────── -->
            <div
                class="hidden flex-wrap items-start justify-between gap-4 sm:flex"
            >
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
                        <p
                            v-if="livreur.telephone"
                            class="text-sm text-muted-foreground"
                        >
                            {{ formatPhone(livreur.telephone) }}
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

            <!-- ── KPIs ───────────────────────────────────────────────────────── -->
            <div class="grid grid-cols-3 gap-3">
                <!-- Vue période sélectionnée -->
                <template v-if="periode_stats">
                    <div
                        class="rounded-lg border bg-card px-3 py-3 text-center sm:px-4"
                    >
                        <p
                            class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            Commission période
                        </p>
                        <p
                            class="mt-0.5 text-sm font-semibold tabular-nums sm:text-base"
                        >
                            {{ formatGNF(periode_stats.total_commission) }}
                        </p>
                    </div>
                    <div
                        class="rounded-lg border bg-card px-3 py-3 text-center sm:px-4"
                    >
                        <p
                            class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            Déjà payé
                        </p>
                        <p
                            class="mt-0.5 text-sm font-semibold tabular-nums sm:text-base"
                        >
                            {{ formatGNF(periode_stats.total_verse) }}
                        </p>
                    </div>
                    <div
                        class="rounded-lg border bg-card px-3 py-3 text-center sm:px-4"
                        :class="
                            periode_stats.reste > 0
                                ? 'border-amber-200 dark:border-amber-900'
                                : ''
                        "
                    >
                        <p
                            class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            Reste à payer
                        </p>
                        <p
                            class="mt-0.5 text-sm font-semibold tabular-nums sm:text-base"
                            :class="
                                periode_stats.reste > 0
                                    ? 'text-amber-600 dark:text-amber-400'
                                    : 'text-foreground'
                            "
                        >
                            {{ formatGNF(periode_stats.reste) }}
                        </p>
                    </div>
                </template>
                <!-- Vue toutes périodes (KPIs globaux) -->
                <template v-else>
                    <div
                        class="rounded-lg border bg-card px-3 py-3 text-center sm:px-4"
                    >
                        <p
                            class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            En attente
                        </p>
                        <p
                            class="mt-0.5 text-sm font-semibold text-zinc-500 tabular-nums sm:text-base dark:text-zinc-400"
                        >
                            {{ formatGNF(kpis.pending) }}
                        </p>
                    </div>
                    <div
                        class="rounded-lg border bg-card px-3 py-3 text-center sm:px-4"
                        :class="
                            kpis.available > 0
                                ? 'border-amber-200 dark:border-amber-900'
                                : ''
                        "
                    >
                        <p
                            class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            A payer
                        </p>
                        <p
                            class="mt-0.5 text-sm font-semibold tabular-nums sm:text-base"
                            :class="
                                kpis.available > 0
                                    ? 'text-amber-600 dark:text-amber-400'
                                    : 'text-foreground'
                            "
                        >
                            {{ formatGNF(kpis.available) }}
                        </p>
                    </div>
                    <div
                        class="rounded-lg border bg-card px-3 py-3 text-center sm:px-4"
                    >
                        <p
                            class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            Déjà payé
                        </p>
                        <p
                            class="mt-0.5 text-sm font-semibold tabular-nums sm:text-base dark:text-zinc-400"
                        >
                            {{ formatGNF(kpis.paid) }}
                        </p>
                    </div>
                </template>
            </div>

            <!-- ── Section détail transferts ─────────────────────────────────── -->
            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <!-- En-tête + filtre -->
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3 sm:px-5 sm:py-3.5"
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
                        <StatusDot
                            v-if="periode_stats"
                            :label="periode_stats.statut_label"
                            :dot-class="periode_stats.statut_dot_class"
                            class="text-xs text-muted-foreground"
                        />
                    </div>
                    <Dropdown
                        v-model="selectedPeriode"
                        :options="periodeOptions"
                        option-label="label"
                        option-value="code"
                        placeholder="Toutes les périodes"
                        class="w-full text-sm sm:w-52"
                        @change="onPeriodeChange(selectedPeriode)"
                    />
                </div>

                <!-- ── MOBILE : cartes ───────────────────────────────────────── -->
                <div v-if="parts.length > 0" class="divide-y sm:hidden">
                    <template v-for="group in partsGrouped" :key="group.code">
                        <!-- En-tête période (visible uniquement en vue "toutes périodes") -->
                        <div
                            v-if="!periode_stats"
                            class="flex items-center justify-between bg-muted/30 px-4 py-2"
                        >
                            <span
                                class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-semibold text-blue-700 dark:bg-blue-900/40 dark:text-blue-300"
                            >
                                <CalendarDays class="h-3 w-3" />
                                {{ group.label }}
                            </span>
                            <StatusDot
                                :label="group.statut_label"
                                :dot-class="group.statut_dot_class"
                                class="text-xs text-muted-foreground"
                            />
                        </div>
                        <!-- Cartes transferts -->
                        <div
                            v-for="part in group.parts"
                            :key="part.id"
                            class="px-4 py-3"
                        >
                            <div
                                class="flex items-center justify-between gap-2"
                            >
                                <span
                                    class="font-mono text-sm font-semibold text-primary"
                                    >{{ part.transfert_reference ?? '—' }}</span
                                >
                                <span
                                    class="flex items-center gap-1 text-xs text-muted-foreground tabular-nums"
                                >
                                    <CalendarClock class="h-3 w-3" />
                                    {{ part.earned_at ?? '—' }}
                                </span>
                            </div>
                            <div class="mt-1.5 text-xs text-muted-foreground">
                                Commission nette :
                                <span
                                    class="ml-1 font-semibold text-foreground tabular-nums"
                                    >{{ formatGNF(part.montant_net) }}</span
                                >
                            </div>
                        </div>
                        <!-- Sous-total (vue toutes périodes uniquement) -->
                        <div
                            v-if="!periode_stats"
                            class="flex items-center justify-between border-t bg-muted/10 px-4 py-2"
                        >
                            <span class="text-xs text-muted-foreground"
                                >Sous-total {{ group.label }}</span
                            >
                            <span class="text-sm font-bold tabular-nums">{{
                                formatGNF(group.total_net)
                            }}</span>
                        </div>
                    </template>
                    <!-- Total global (vue toutes périodes, plusieurs groupes) -->
                    <div
                        v-if="!periode_stats && partsGrouped.length > 1"
                        class="flex items-center justify-between border-t-2 bg-muted/20 px-4 py-3"
                    >
                        <span
                            class="text-xs font-bold text-muted-foreground uppercase"
                            >Total toutes périodes</span
                        >
                        <span class="text-sm font-bold tabular-nums">{{
                            formatGNF(
                                parts.reduce((s, p) => s + p.montant_net, 0),
                            )
                        }}</span>
                    </div>
                </div>

                <!-- ── DESKTOP : table ────────────────────────────────────────── -->
                <div
                    v-if="parts.length > 0"
                    class="hidden overflow-x-auto sm:block"
                >
                    <table class="w-full table-fixed text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40">
                                <th
                                    class="w-[48%] px-4 py-3 text-left font-medium text-muted-foreground"
                                >
                                    Transfert
                                </th>
                                <th
                                    class="w-[22%] px-4 py-3 text-left font-medium text-muted-foreground"
                                >
                                    Acquis le
                                </th>
                                <th
                                    class="w-[30%] px-4 py-3 text-right font-medium text-muted-foreground"
                                >
                                    Commission nette
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <template
                                v-for="group in partsGrouped"
                                :key="group.code"
                            >
                                <!-- En-tête période (vue toutes périodes uniquement) -->
                                <tr v-if="!periode_stats" class="bg-muted/30">
                                    <td colspan="3" class="px-4 py-2">
                                        <div
                                            class="flex items-center justify-between"
                                        >
                                            <span
                                                class="inline-flex items-center gap-1.5 rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-semibold text-blue-700 dark:bg-blue-900/40 dark:text-blue-300"
                                            >
                                                <CalendarDays
                                                    class="h-3.5 w-3.5"
                                                />
                                                {{ group.label }}
                                            </span>
                                            <StatusDot
                                                :label="group.statut_label"
                                                :dot-class="
                                                    group.statut_dot_class
                                                "
                                                class="text-xs text-muted-foreground"
                                            />
                                        </div>
                                    </td>
                                </tr>
                                <!-- Lignes transferts -->
                                <tr
                                    v-for="part in group.parts"
                                    :key="part.id"
                                    class="border-b transition-colors hover:bg-muted/10"
                                >
                                    <td
                                        class="px-4 py-3 font-mono text-sm font-semibold text-primary"
                                    >
                                        {{ part.transfert_reference ?? '—' }}
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
                                </tr>
                                <!-- Sous-total (vue toutes périodes uniquement) -->
                                <tr
                                    v-if="!periode_stats"
                                    class="border-b-2 bg-muted/5"
                                >
                                    <td
                                        colspan="2"
                                        class="px-4 py-2 text-xs text-muted-foreground"
                                    >
                                        Sous-total {{ group.label }}
                                    </td>
                                    <td
                                        class="px-4 py-2 text-right text-sm font-bold tabular-nums"
                                    >
                                        {{ formatGNF(group.total_net) }}
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot v-if="!periode_stats && partsGrouped.length > 1">
                            <tr class="border-t-2 bg-muted/20 font-semibold">
                                <td
                                    colspan="2"
                                    class="px-4 py-2.5 text-xs font-bold text-muted-foreground uppercase"
                                >
                                    Total toutes périodes
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
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div
                    v-if="parts.length === 0"
                    class="py-12 text-center text-sm text-muted-foreground"
                >
                    <span v-if="selected_periode"
                        >Aucune commission pour la période sélectionnée.</span
                    >
                    <span v-else
                        >Aucune commission enregistrée pour ce livreur.</span
                    >
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
                    Solde A payer (toutes périodes) :
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
