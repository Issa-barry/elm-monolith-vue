<script setup lang="ts">
import AuditTimeline from '@/components/AuditTimeline.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, CalendarDays, HandCoins } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import { computed, reactive, ref } from 'vue';

interface PartRow {
    id: string;
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
    id: string;
    montant: number;
    mode_paiement: string;
    note: string | null;
    paid_at: string | null;
    created_by: string | null;
}

interface PeriodeOption {
    code: string;
    label: string;
}
interface ModePaiement {
    value: string;
    label: string;
}

const props = defineProps<{
    livreur: { id: string; nom: string; telephone: string | null };
    kpis: {
        total_brut: number;
        total_frais: number;
        total_net: number;
        total_verse: number;
        impaye: number;
        paye: number;
    };
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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Comptabilité', href: '/comptabilite' },
    {
        title: 'Commission logistique',
        href: '/comptabilite/commissions/logistique',
    },
    { title: props.livreur.nom, href: '' },
];

const selectedPeriode = ref<string>(props.selected_periode);
const periodeOptions: PeriodeOption[] = [
    { code: '', label: 'Toutes les périodes' },
    ...props.periodes_disponibles,
];

function onPeriodeChange(value: string) {
    const params: Record<string, string> = {};
    if (value) params.periode = value;
    router.get(
        `/comptabilite/commissions/logistique/livreurs/${props.livreur.id}`,
        params,
        {
            preserveScroll: true,
            replace: true,
        },
    );
}

// Groupement par période
interface PeriodeGroup {
    code: string;
    label: string;
    statut_label: string;
    statut_dot_class: string;
    total_net: number;
    parts: PartRow[];
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
        const all = g.parts;
        if (all.every((p) => p.statut === 'paye')) {
            g.statut_label = 'Soldée';
            g.statut_dot_class = 'bg-emerald-500';
        } else if (all.every((p) => p.statut === 'impaye')) {
            g.statut_label = 'Non versée';
            g.statut_dot_class = 'bg-red-500';
        } else {
            g.statut_label = 'Partiellement versée';
            g.statut_dot_class = 'bg-amber-500';
        }
    }
    return Array.from(map.values());
});

// Dialog paiement
const showPaiementDialog = ref(false);
const paiementForm = reactive({
    montant: null as number | null,
    mode_paiement: 'especes',
    note: '',
    processing: false,
    errors: {} as Record<string, string>,
});

function openPaiement() {
    paiementForm.montant = props.kpis.impaye > 0 ? props.kpis.impaye : null;
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
        `/comptabilite/commissions/logistique/livreurs/${props.livreur.id}/paiements`,
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

const activeTab = ref<'informations' | 'paiements' | 'historique'>(
    'informations',
);

function fmt(val: number) {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

function formatMode(mode: string) {
    return props.modes_paiement.find((m) => m.value === mode)?.label ?? mode;
}
</script>

<template>
    <Head :title="`Commissions — ${livreur.nom}`" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-5xl space-y-6 px-4 py-6 sm:px-6">
            <!-- En-tête -->
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-center gap-3">
                    <Link
                        href="/comptabilite/commissions/logistique"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground hover:bg-muted/80"
                    >
                        <ArrowLeft class="h-4 w-4" />
                    </Link>
                    <div>
                        <p
                            class="text-xs font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                        >
                            Livreur — Logistique
                        </p>
                        <p class="mt-0.5 text-xl font-semibold">
                            {{ livreur.nom }}
                        </p>
                        <p
                            v-if="livreur.telephone"
                            class="text-sm text-muted-foreground"
                        >
                            {{ livreur.telephone }}
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button
                        v-if="can_payer && kpis.impaye > 0"
                        size="sm"
                        @click="openPaiement"
                    >
                        <HandCoins class="mr-1.5 h-4 w-4" />
                        Payer {{ fmt(kpis.impaye) }}
                    </Button>
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex border-b">
                <button
                    type="button"
                    class="px-4 py-2 text-sm font-medium transition-colors"
                    :class="
                        activeTab === 'informations'
                            ? 'border-b-2 border-primary text-primary'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="activeTab = 'informations'"
                >
                    Informations
                </button>
                <button
                    type="button"
                    class="px-4 py-2 text-sm font-medium transition-colors"
                    :class="
                        activeTab === 'paiements'
                            ? 'border-b-2 border-primary text-primary'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="activeTab = 'paiements'"
                >
                    Paiements
                    <span
                        v-if="payments.length > 0"
                        class="ml-1 rounded-full bg-muted px-1.5 py-0.5 text-[10px] tabular-nums"
                        >{{ payments.length }}</span
                    >
                </button>
                <button
                    type="button"
                    class="px-4 py-2 text-sm font-medium transition-colors"
                    :class="
                        activeTab === 'historique'
                            ? 'border-b-2 border-primary text-primary'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="activeTab = 'historique'"
                >
                    Historique
                </button>
            </div>

            <template v-if="activeTab === 'informations'">
                <!-- KPIs — 5 cartes uniformes -->
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                    <div class="rounded-lg border bg-card p-4 text-center">
                        <p class="text-base font-bold tabular-nums">
                            {{ fmt(kpis.total_brut) }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Brut cumulé
                        </p>
                    </div>
                    <div class="rounded-lg border bg-card p-4 text-center">
                        <p
                            class="text-base font-bold text-red-600 tabular-nums dark:text-red-400"
                        >
                            {{
                                kpis.total_frais > 0
                                    ? '-' + fmt(kpis.total_frais)
                                    : fmt(0)
                            }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">Frais</p>
                    </div>
                    <div class="rounded-lg border bg-card p-4 text-center">
                        <p class="text-base font-bold tabular-nums">
                            {{ fmt(kpis.total_net) }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Net à payer
                        </p>
                    </div>
                    <div class="rounded-lg border bg-card p-4 text-center">
                        <p
                            class="text-base font-bold text-emerald-600 tabular-nums dark:text-emerald-400"
                        >
                            {{ fmt(kpis.total_verse) }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Déjà payé
                        </p>
                    </div>
                    <div
                        class="rounded-lg border bg-card p-4 text-center"
                        :class="
                            kpis.impaye > 0
                                ? 'border-amber-200 dark:border-amber-900'
                                : ''
                        "
                    >
                        <p
                            class="text-base font-bold tabular-nums"
                            :class="
                                kpis.impaye > 0
                                    ? 'text-amber-600 dark:text-amber-400'
                                    : ''
                            "
                        >
                            {{ fmt(kpis.impaye) }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Reste à payer
                        </p>
                    </div>
                </div>

                <!-- Tableau des parts -->
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
                            class="w-full text-sm sm:w-64"
                            @change="onPeriodeChange(selectedPeriode)"
                        />
                    </div>

                    <div v-if="parts.length > 0">
                        <template
                            v-for="group in partsGrouped"
                            :key="group.code"
                        >
                            <div
                                v-if="!periode_stats"
                                class="flex items-center justify-between border-b bg-muted/30 px-4 py-2"
                            >
                                <div class="flex items-center gap-2">
                                    <CalendarDays
                                        class="h-3.5 w-3.5 text-muted-foreground"
                                    />
                                    <span
                                        class="text-xs font-semibold text-muted-foreground"
                                        >{{ group.label }}</span
                                    >
                                    <StatusDot
                                        :label="group.statut_label"
                                        :dot-class="group.statut_dot_class"
                                        class="text-xs text-muted-foreground"
                                    />
                                </div>
                                <span
                                    class="text-xs font-semibold tabular-nums"
                                    >{{ fmt(group.total_net) }}</span
                                >
                            </div>
                            <table class="w-full text-sm">
                                <tbody class="divide-y">
                                    <tr
                                        v-for="part in group.parts"
                                        :key="part.id"
                                        class="hover:bg-muted/10"
                                    >
                                        <td
                                            class="px-4 py-3 font-mono text-xs text-muted-foreground"
                                        >
                                            {{
                                                part.transfert_reference ?? '—'
                                            }}
                                        </td>
                                        <td
                                            class="px-4 py-3 text-xs text-muted-foreground"
                                        >
                                            {{ part.earned_at ?? '—' }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <StatusDot
                                                :label="part.statut_label"
                                                :dot-class="
                                                    part.statut_dot_class
                                                "
                                                class="text-xs text-muted-foreground"
                                            />
                                        </td>
                                        <td
                                            class="px-4 py-3 text-right font-medium tabular-nums"
                                        >
                                            {{ fmt(part.montant_net) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </template>
                    </div>
                    <div
                        v-else
                        class="flex flex-col items-center gap-3 py-12 text-muted-foreground"
                    >
                        <HandCoins class="h-10 w-10 opacity-30" />
                        <p class="text-sm">
                            Aucune commission pour cette période.
                        </p>
                    </div>
                </div>
            </template>

            <template v-if="activeTab === 'paiements'">
                <div class="rounded-xl border bg-card">
                    <div class="border-b px-4 py-3">
                        <h2 class="text-sm font-semibold">
                            Paiements enregistrés
                        </h2>
                    </div>
                    <div v-if="payments.length > 0" class="divide-y">
                        <div
                            v-for="p in payments"
                            :key="p.id"
                            class="flex items-start justify-between gap-3 px-4 py-3"
                        >
                            <div>
                                <p class="text-sm font-medium">
                                    {{ fmt(p.montant) }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ p.paid_at }} ·
                                    {{ formatMode(p.mode_paiement) }}
                                </p>
                                <p
                                    v-if="p.note"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ p.note }}
                                </p>
                                <p
                                    v-if="p.created_by"
                                    class="text-xs text-muted-foreground/60"
                                >
                                    Par {{ p.created_by }}
                                </p>
                            </div>
                        </div>
                    </div>
                    <p
                        v-else
                        class="py-8 text-center text-sm text-muted-foreground"
                    >
                        Aucun paiement enregistré.
                    </p>
                </div>
            </template>

            <template v-if="activeTab === 'historique'">
                <div class="rounded-xl border bg-card p-5">
                    <AuditTimeline
                        auditable-type="App\Models\Livreur"
                        :auditable-id="livreur.id"
                        module="commission_logistique"
                    />
                </div>
            </template>
        </div>
    </AppLayout>

    <!-- Dialog paiement -->
    <Dialog
        v-model:visible="showPaiementDialog"
        modal
        :style="{ width: '420px' }"
        header="Enregistrer un paiement"
    >
        <div class="flex flex-col gap-4 py-2">
            <div class="flex flex-col gap-1.5">
                <Label>Montant (GNF)</Label>
                <InputNumber
                    v-model="paiementForm.montant"
                    :min="1"
                    :max="kpis.impaye"
                    :use-grouping="true"
                    class="w-full"
                    input-class="w-full"
                    suffix=" GNF"
                    locale="fr-FR"
                    autofocus
                />
                <p
                    v-if="paiementForm.errors.montant"
                    class="text-xs text-destructive"
                >
                    {{ paiementForm.errors.montant }}
                </p>
                <p class="text-xs text-muted-foreground">
                    Disponible : {{ fmt(kpis.impaye) }}
                </p>
            </div>
            <div class="flex flex-col gap-1.5">
                <Label>Mode de paiement</Label>
                <Dropdown
                    v-model="paiementForm.mode_paiement"
                    :options="modes_paiement"
                    option-label="label"
                    option-value="value"
                    class="w-full text-sm"
                />
            </div>
            <div class="flex flex-col gap-1.5">
                <Label>Note (optionnel)</Label>
                <textarea
                    v-model="paiementForm.note"
                    rows="2"
                    class="w-full resize-none rounded-lg border border-input bg-background px-3 py-2 text-sm focus:ring-2 focus:ring-ring focus:outline-none"
                />
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    @click="showPaiementDialog = false"
                    >Annuler</Button
                >
                <Button
                    size="sm"
                    :disabled="paiementForm.processing || !paiementForm.montant"
                    @click="submitPaiement"
                >
                    <HandCoins class="mr-1.5 h-4 w-4" />
                    {{
                        paiementForm.processing
                            ? 'Enregistrement…'
                            : 'Confirmer'
                    }}
                </Button>
            </div>
        </template>
    </Dialog>
</template>
