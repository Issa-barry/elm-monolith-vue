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

interface CommandeRow {
    commission_id: string;
    commande_reference: string | null;
    date_commande: string | null;
    site: string | null;
    vehicule: string | null;
    montant_brut: number;
    montant_net: number;
    montant_verse: number;
    periode: string | null;
    periode_label: string | null;
}

interface FraisRow {
    id: string;
    date: string;
    type: string;
    montant: number;
    commentaire: string | null;
}

interface PaiementRow {
    id: string;
    paid_at: string | null;
    montant: number;
    mode_paiement: string;
    note: string | null;
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
    proprietaire: { id: string; nom: string; telephone: string | null };
    resume_global: {
        total_brut_cumule: number;
        total_frais_depenses: number;
        total_net_cumule: number;
        total_verse: number;
        solde_global: number;
    };
    frais_depenses: FraisRow[];
    historique_commandes: CommandeRow[];
    historique_paiements: PaiementRow[];
    modes_paiement: ModePaiement[];
    periode_courante: string;
    selected_periode: string;
    periodes_disponibles: PeriodeOption[];
    can_payer: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Comptabilité', href: '/comptabilite' },
    {
        title: 'Commission propriétaire',
        href: '/comptabilite/commissions/proprietaires',
    },
    { title: props.proprietaire.nom, href: '' },
];

const periodeFiltre = ref(props.selected_periode ?? '');
const PERIODE_OPTIONS = [
    { code: '', label: 'Toutes les périodes' },
    ...props.periodes_disponibles,
];

function changePeriode(code: string) {
    const params: Record<string, string> = {};
    if (code) params.periode = code;
    router.get(
        `/comptabilite/commissions/proprietaires/${props.proprietaire.id}`,
        params,
        { preserveScroll: true, replace: true },
    );
}

// Groupement par période
interface PeriodeGroup {
    code: string;
    label: string;
    statut_label: string;
    statut_dot_class: string;
    total_brut: number;
    commandes: CommandeRow[];
}

const commandesGrouped = computed<PeriodeGroup[]>(() => {
    const map = new Map<string, PeriodeGroup>();
    for (const c of props.historique_commandes) {
        const key = c.periode ?? '__sans__';
        if (!map.has(key)) {
            map.set(key, {
                code: key,
                label: c.periode_label ?? c.periode ?? '—',
                statut_label: '',
                statut_dot_class: '',
                total_brut: 0,
                commandes: [],
            });
        }
        const g = map.get(key)!;
        g.commandes.push(c);
        g.total_brut += c.montant_brut;
    }
    for (const g of map.values()) {
        const totalVerse = g.commandes.reduce((s, c) => s + c.montant_verse, 0);
        const totalBrut = g.total_brut;
        if (totalVerse >= totalBrut && totalBrut > 0) {
            g.statut_label = 'Soldée';
            g.statut_dot_class = 'bg-emerald-500';
        } else if (totalVerse === 0) {
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
    paiementForm.montant =
        props.resume_global.solde_global > 0
            ? props.resume_global.solde_global
            : null;
    paiementForm.mode_paiement = 'especes';
    paiementForm.note = '';
    paiementForm.processing = false;
    paiementForm.errors = {};
    showPaiementDialog.value = true;
}

function submitPaiement() {
    if (!paiementForm.montant) return;
    paiementForm.processing = true;
    paiementForm.errors = {};
    router.post(
        `/comptabilite/commissions/proprietaires/${props.proprietaire.id}/paiements`,
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

const activeTab = ref<'informations' | 'paiements' | 'depenses' | 'historique'>(
    'informations',
);

function fmt(val: number | null | undefined) {
    return (
        new Intl.NumberFormat('fr-FR').format(Math.round(Number(val ?? 0))) +
        ' GNF'
    );
}

function formatMode(mode: string) {
    return props.modes_paiement.find((m) => m.value === mode)?.label ?? mode;
}
</script>

<template>
    <Head :title="`Commission propriétaire — ${proprietaire.nom}`" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-5xl space-y-6 px-4 py-6 sm:px-6">
            <!-- En-tête -->
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-center gap-3">
                    <Link
                        href="/comptabilite/commissions/proprietaires"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground hover:bg-muted/80"
                    >
                        <ArrowLeft class="h-4 w-4" />
                    </Link>
                    <div>
                        <p
                            class="text-xs font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                        >
                            Propriétaire
                        </p>
                        <p class="mt-0.5 text-xl font-semibold">
                            {{ proprietaire.nom }}
                        </p>
                        <p
                            v-if="proprietaire.telephone"
                            class="text-sm text-muted-foreground"
                        >
                            {{ proprietaire.telephone }}
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button
                        v-if="can_payer && resume_global.solde_global > 0"
                        size="sm"
                        @click="openPaiement"
                    >
                        <HandCoins class="mr-1.5 h-4 w-4" />
                        Payer {{ fmt(resume_global.solde_global) }}
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
                        v-if="historique_paiements.length > 0"
                        class="ml-1 rounded-full bg-muted px-1.5 py-0.5 text-[10px] tabular-nums"
                        >{{ historique_paiements.length }}</span
                    >
                </button>
                <button
                    type="button"
                    class="px-4 py-2 text-sm font-medium transition-colors"
                    :class="
                        activeTab === 'depenses'
                            ? 'border-b-2 border-primary text-primary'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="activeTab = 'depenses'"
                >
                    Dépenses
                    <span
                        v-if="frais_depenses.length > 0"
                        class="ml-1 rounded-full bg-muted px-1.5 py-0.5 text-[10px] tabular-nums"
                        >{{ frais_depenses.length }}</span
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
                <!-- KPIs globaux -->
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                    <div class="rounded-lg border bg-card p-4 text-center">
                        <p class="text-base font-bold tabular-nums">
                            {{ fmt(resume_global.total_brut_cumule) }}
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
                                resume_global.total_frais_depenses > 0
                                    ? '-' +
                                      fmt(resume_global.total_frais_depenses)
                                    : fmt(0)
                            }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Frais véhicules
                        </p>
                    </div>
                    <div class="rounded-lg border bg-card p-4 text-center">
                        <p class="text-base font-bold tabular-nums">
                            {{ fmt(resume_global.total_net_cumule) }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Net à payer
                        </p>
                    </div>
                    <div class="rounded-lg border bg-card p-4 text-center">
                        <p
                            class="text-base font-bold text-emerald-600 tabular-nums dark:text-emerald-400"
                        >
                            {{ fmt(resume_global.total_verse) }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Déjà payé
                        </p>
                    </div>
                    <div
                        class="rounded-lg border bg-card p-4 text-center"
                        :class="
                            resume_global.solde_global > 0
                                ? 'border-amber-200 dark:border-amber-900'
                                : ''
                        "
                    >
                        <p
                            class="text-base font-bold tabular-nums"
                            :class="
                                resume_global.solde_global > 0
                                    ? 'text-amber-600 dark:text-amber-400'
                                    : ''
                            "
                        >
                            {{ fmt(resume_global.solde_global) }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Reste à payer
                        </p>
                    </div>
                </div>

                <!-- Tableau commandes -->
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
                                >{{ historique_commandes.length }}</span
                            >
                        </div>
                        <Dropdown
                            v-model="periodeFiltre"
                            :options="PERIODE_OPTIONS"
                            option-label="label"
                            option-value="code"
                            placeholder="Toutes les périodes"
                            class="w-full text-sm sm:w-56"
                            @change="changePeriode(periodeFiltre)"
                        />
                    </div>

                    <div v-if="historique_commandes.length > 0">
                        <template
                            v-for="group in commandesGrouped"
                            :key="group.code"
                        >
                            <div
                                v-if="!periodeFiltre"
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
                                    >{{ fmt(group.total_brut) }}</span
                                >
                            </div>
                            <table class="w-full text-sm">
                                <tbody class="divide-y">
                                    <tr
                                        v-for="c in group.commandes"
                                        :key="c.commission_id"
                                        class="hover:bg-muted/10"
                                    >
                                        <td
                                            class="px-4 py-3 font-mono text-xs text-muted-foreground"
                                        >
                                            {{ c.commande_reference ?? '—' }}
                                        </td>
                                        <td
                                            class="px-4 py-3 text-xs text-muted-foreground"
                                        >
                                            {{ c.date_commande ?? '—' }}
                                        </td>
                                        <td
                                            class="px-4 py-3 text-right font-medium tabular-nums"
                                        >
                                            {{ fmt(c.montant_brut) }}
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
                    <div
                        v-if="historique_paiements.length > 0"
                        class="divide-y"
                    >
                        <div
                            v-for="p in historique_paiements"
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

            <template v-if="activeTab === 'depenses'">
                <div
                    class="overflow-hidden rounded-xl border bg-card shadow-sm"
                >
                    <div class="border-b px-4 py-3">
                        <div class="flex items-center gap-2">
                            <h2
                                class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                Dépenses véhicules
                            </h2>
                            <span
                                class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground tabular-nums"
                                >{{ frais_depenses.length }}</span
                            >
                        </div>
                    </div>

                    <div v-if="frais_depenses.length > 0">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b bg-muted/40">
                                    <th
                                        class="px-4 py-3 text-left font-medium text-muted-foreground"
                                    >
                                        Date
                                    </th>
                                    <th
                                        class="px-4 py-3 text-left font-medium text-muted-foreground"
                                    >
                                        Type
                                    </th>
                                    <th
                                        class="px-4 py-3 text-right font-medium text-muted-foreground"
                                    >
                                        Montant
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="f in frais_depenses"
                                    :key="f.id"
                                    class="hover:bg-muted/10"
                                >
                                    <td
                                        class="px-4 py-3 text-xs text-muted-foreground"
                                    >
                                        {{ f.date }}
                                    </td>
                                    <td class="px-4 py-3 text-sm">
                                        {{ f.type }}
                                        <span
                                            v-if="f.commentaire"
                                            class="block text-xs text-muted-foreground"
                                            >{{ f.commentaire }}</span
                                        >
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right font-medium text-red-600 tabular-nums dark:text-red-400"
                                    >
                                        -{{ fmt(f.montant) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <div
                            class="flex items-center justify-between border-t bg-muted/30 px-4 py-3"
                        >
                            <p class="text-sm font-semibold">Total dépenses</p>
                            <p
                                class="text-sm font-bold text-red-600 tabular-nums dark:text-red-400"
                            >
                                -{{ fmt(resume_global.total_frais_depenses) }}
                            </p>
                        </div>
                    </div>
                    <p
                        v-else
                        class="py-8 text-center text-sm text-muted-foreground"
                    >
                        Aucune dépense pour cette période.
                    </p>
                </div>
            </template>

            <template v-if="activeTab === 'historique'">
                <div class="rounded-xl border bg-card p-5">
                    <AuditTimeline
                        auditable-type="App\Models\Proprietaire"
                        :auditable-id="proprietaire.id"
                        module="commission_proprietaire"
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
                    :max="resume_global.solde_global"
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
                    Disponible : {{ fmt(resume_global.solde_global) }}
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
