<script setup lang="ts">
import AuditTimeline from '@/components/AuditTimeline.vue';
import PaymentDialogCompact from '@/components/PaymentDialogCompact.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, HandCoins } from 'lucide-vue-next';
import Dropdown from 'primevue/dropdown';
import { ref } from 'vue';

interface CommandeRow {
    commission_id: string;
    commande_reference: string | null;
    date_commande: string | null;
    site: string | null;
    vehicule: string | null;
    montant_brut: number;
    frais: number;
    montant_net: number;
    montant_verse: number;
    periode: string | null;
    periode_label: string | null;
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

interface DepenseRow {
    id: string;
    date_depense: string | null;
    montant: number;
    statut: string;
    statut_label: string;
    saisi_par: string | null;
    validateur: string | null;
    date_validation: string | null;
    commentaire: string | null;
}

const props = defineProps<{
    livreur: { id: string; nom: string; telephone: string | null };
    resume_global: {
        total_brut_cumule: number;
        total_frais: number;
        total_net_cumule: number;
        total_verse: number;
        solde_global: number;
    };
    historique_commandes: CommandeRow[];
    historique_paiements: PaiementRow[];
    depenses: DepenseRow[];
    modes_paiement: ModePaiement[];
    periode_courante: string;
    periode_courante_label: string;
    selected_periode: string;
    periodes_disponibles: PeriodeOption[];
    periode_stats: {
        code: string;
        label: string;
        total_commission: number;
        total_verse: number;
        reste: number;
    } | null;
    can_payer: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Comptabilité', href: '/comptabilite' },
    { title: 'Commission vente', href: '/comptabilite/commissions/vente' },
    { title: props.livreur.nom, href: '' },
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
        `/comptabilite/commissions/vente/livreurs/${props.livreur.id}`,
        params,
        { preserveScroll: true, replace: true },
    );
}

// Dialog paiement
const showPaiementDialog = ref(false);
const paiementProcessing = ref(false);
const paiementErrors = ref<Record<string, string>>({});

function openPaiement() {
    showPaiementDialog.value = true;
}

function handlePaiementSubmit(payload: {
    montant: number;
    mode_paiement: string;
}) {
    paiementProcessing.value = true;
    paiementErrors.value = {};
    router.post(
        `/comptabilite/commissions/vente/livreurs/${props.livreur.id}/paiements`,
        payload,
        {
            preserveScroll: true,
            onSuccess: () => {
                showPaiementDialog.value = false;
            },
            onError: (e) => {
                paiementErrors.value = e as Record<string, string>;
            },
            onFinish: () => {
                paiementProcessing.value = false;
            },
        },
    );
}

const activeTab = ref<
    'informations' | 'depenses' | 'paiements' | 'historique'
>('informations');

function fmt(val: number | null | undefined) {
    return (
        new Intl.NumberFormat('fr-FR').format(Math.round(Number(val ?? 0))) +
        ' GNF'
    );
}

function formatMode(mode: string) {
    return props.modes_paiement.find((m) => m.value === mode)?.label ?? mode;
}

const statutDepenseVariant: Record<
    string,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    brouillon: 'secondary',
    soumis: 'outline',
    valide: 'default',
    rejete: 'destructive',
    annule: 'destructive',
};

const statutDepenseColors: Record<string, string> = {
    brouillon: '',
    soumis: 'border-blue-400 text-blue-700',
    valide: 'bg-emerald-100 text-emerald-700 border-emerald-300',
    rejete: 'bg-orange-100 text-orange-700 border-orange-300',
    annule: '',
};
</script>

<template>
    <Head :title="`Commission vente — ${livreur.nom}`" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-5xl space-y-6 px-4 py-6 sm:px-6">
            <!-- En-tête -->
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-center gap-3">
                    <Link
                        href="/comptabilite/commissions/vente"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground hover:bg-muted/80"
                    >
                        <ArrowLeft class="h-4 w-4" />
                    </Link>
                    <div>
                        <p
                            class="text-xs font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                        >
                            Livreur — vente
                        </p>
                        <p class="mt-0.5 text-xl font-semibold">
                            {{ livreur.nom }}
                        </p>
                        <p
                            v-if="livreur.telephone"
                            class="text-sm text-muted-foreground"
                        >
                            {{ formatPhoneDisplay(livreur.telephone) }}
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

            <!-- KPIs globaux : visibles peu importe l'onglet actif -->
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
                            resume_global.total_frais > 0
                                ? '-' + fmt(resume_global.total_frais)
                                : fmt(0)
                        }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">Frais</p>
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
                        activeTab === 'depenses'
                            ? 'border-b-2 border-primary text-primary'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="activeTab = 'depenses'"
                >
                    Dépenses
                    <span
                        v-if="depenses.length > 0"
                        class="ml-1 rounded-full bg-muted px-1.5 py-0.5 text-[10px] tabular-nums"
                        >{{ depenses.length }}</span
                    >
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
                <!-- Tableau commandes avec filtre période -->
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
                                >{{ historique_commandes.length }}</span
                            >
                        </div>
                        <Dropdown
                            v-model="periodeFiltre"
                            :options="PERIODE_OPTIONS"
                            option-label="label"
                            option-value="code"
                            placeholder="Toutes les périodes"
                            class="w-full text-sm sm:w-64"
                            @change="changePeriode(periodeFiltre)"
                        />
                    </div>


                    <table
                        v-if="historique_commandes.length > 0"
                        class="w-full text-sm"
                    >
                        <thead>
                            <tr class="border-b bg-muted/40">
                                <th
                                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                                >
                                    Commande
                                </th>
                                <th
                                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                                >
                                    Date
                                </th>
                                <th
                                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                                >
                                    Période
                                </th>
                                <th
                                    class="px-4 py-3 text-right font-medium text-muted-foreground"
                                >
                                    Net
                                </th>
                                <th
                                    class="px-4 py-3 text-right font-medium text-muted-foreground"
                                >
                                    Payé
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="c in historique_commandes"
                                :key="c.commission_id"
                                class="hover:bg-muted/10"
                            >
                                <td class="px-4 py-3 font-mono text-xs">
                                    {{ c.commande_reference ?? '—' }}
                                </td>
                                <td
                                    class="px-4 py-3 text-xs text-muted-foreground"
                                >
                                    {{ c.date_commande ?? '—' }}
                                </td>
                                <td
                                    class="px-4 py-3 text-xs text-muted-foreground"
                                >
                                    {{ c.periode_label ?? '—' }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right font-medium tabular-nums"
                                >
                                    {{ fmt(c.montant_net) }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right text-emerald-600 tabular-nums dark:text-emerald-400"
                                >
                                    {{ fmt(c.montant_verse) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div
                        v-else
                        class="flex flex-col items-center gap-3 py-12 text-muted-foreground"
                    >
                        <HandCoins class="h-10 w-10 opacity-30" />
                        <p class="text-sm">
                            Aucune commande pour cette période.
                        </p>
                    </div>
                </div>
            </template>

            <template v-if="activeTab === 'depenses'">
                <div
                    class="overflow-hidden rounded-xl border bg-card shadow-sm"
                >
                    <div class="border-b px-4 py-3">
                        <h2 class="text-sm font-semibold">
                            Dépenses imputées à ce livreur
                        </h2>
                    </div>
                    <table v-if="depenses.length > 0" class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40">
                                <th
                                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                                >
                                    Date
                                </th>
                                <th
                                    class="px-4 py-3 text-right font-medium text-muted-foreground"
                                >
                                    Montant
                                </th>
                                <th
                                    class="px-4 py-3 text-center font-medium text-muted-foreground"
                                >
                                    Statut
                                </th>
                                <th
                                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                                >
                                    Saisi par
                                </th>
                                <th
                                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                                >
                                    Validé par
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="d in depenses"
                                :key="d.id"
                                class="hover:bg-muted/10"
                            >
                                <td
                                    class="px-4 py-3 text-xs text-muted-foreground"
                                >
                                    {{ d.date_depense ?? '—' }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right font-medium tabular-nums"
                                >
                                    {{ fmt(d.montant) }}
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <Badge
                                        :variant="
                                            statutDepenseVariant[d.statut] ??
                                            'secondary'
                                        "
                                        :class="statutDepenseColors[d.statut]"
                                    >
                                        {{ d.statut_label }}
                                    </Badge>
                                </td>
                                <td
                                    class="px-4 py-3 text-xs text-muted-foreground"
                                >
                                    {{ d.saisi_par ?? '—' }}
                                </td>
                                <td
                                    class="px-4 py-3 text-xs text-muted-foreground"
                                >
                                    <span v-if="d.validateur">
                                        {{ d.validateur }}
                                        <span
                                            v-if="d.date_validation"
                                            class="text-muted-foreground/60"
                                        >
                                            ({{ d.date_validation }})
                                        </span>
                                    </span>
                                    <span v-else>—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div
                        v-else
                        class="flex flex-col items-center gap-3 py-12 text-muted-foreground"
                    >
                        <HandCoins class="h-10 w-10 opacity-30" />
                        <p class="text-sm">
                            Aucune dépense imputée à ce livreur.
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
                            class="px-4 py-3"
                        >
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
                        module="commission_vente"
                    />
                </div>
            </template>
        </div>
    </AppLayout>

    <PaymentDialogCompact
        v-model:visible="showPaiementDialog"
        :title="`Payer — ${livreur.nom}`"
        :solde="resume_global.solde_global"
        :processing="paiementProcessing"
        :errors="paiementErrors"
        @submit="handlePaiementSubmit"
    />
</template>
