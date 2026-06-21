<script setup lang="ts">
import DataFilters, { type FilterField } from '@/components/filters/DataFilters.vue';
import PaymentDialogCompact from '@/components/PaymentDialogCompact.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { HandCoins, MoreHorizontal, Truck, User } from 'lucide-vue-next';
import { computed, ref } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────

interface LivreurRow {
    livreur_id: number;
    nom: string;
    telephone: string | null;
    vehicules: string | null;
    impaye: number;
    paye: number;
}

interface Kpis {
    nb_livreurs: number;
    total_impaye: number;
    total_paye: number;
}

interface SiteOption {
    value: string;
    label: string;
}

interface PeriodeOption {
    code: string;
    label: string;
}

// ── Props ─────────────────────────────────────────────────────────────────────

const props = defineProps<{
    livreurs: LivreurRow[];
    kpis: Kpis;
    search: string;
    filtre_statut: string;
    filtre_site: string;
    selected_periode: string;
    periodes_disponibles: PeriodeOption[];
    sites: SiteOption[];
    can_payer: boolean;
}>();

// ── Breadcrumbs ───────────────────────────────────────────────────────────────

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Commissions logistiques', href: '/logistique/commissions' },
];

// ── Filtres ───────────────────────────────────────────────────────────────────

const search = ref(props.search ?? '');

const filterFields = computed<FilterField[]>(() => [
    {
        key: 'statut',
        label: 'Statut',
        type: 'select',
        options: [
            { value: 'impaye', label: 'Impayé' },
            { value: 'paye', label: 'Payé' },
        ],
        placeholder: 'Tous les statuts',
    },
    {
        key: 'periode',
        label: 'Période',
        type: 'select',
        options: (props.periodes_disponibles ?? []).map((p) => ({
            value: p.code,
            label: p.label,
        })),
        placeholder: 'Toutes les périodes',
    },
    ...(props.sites && props.sites.length > 0
        ? [
              {
                  key: 'site',
                  label: 'Agence',
                  type: 'select' as const,
                  options: props.sites.map((s) => ({
                      value: s.value,
                      label: s.label,
                  })),
                  placeholder: 'Toutes les agences',
              },
          ]
        : []),
]);

const filterValues = computed(() => ({
    statut: props.filtre_statut || '',
    periode: props.selected_periode || '',
    site: props.filtre_site || '',
}));

// ── KPIs calculés ─────────────────────────────────────────────────────────────

const kpiTotalCumule = computed(
    () => (props.kpis?.total_impaye ?? 0) + (props.kpis?.total_paye ?? 0),
);

// ── Paiement ──────────────────────────────────────────────────────────────────

const showPaiementDialog = ref(false);
const selectedLivreur = ref<LivreurRow | null>(null);
const paiementProcessing = ref(false);
const paiementErrors = ref<Record<string, string>>({});

function openPaiement(livreur: LivreurRow) {
    selectedLivreur.value = livreur;
    showPaiementDialog.value = true;
}

function handlePaiementSubmit(payload: {
    montant: number;
    mode_paiement: string;
}) {
    if (!selectedLivreur.value) return;
    paiementProcessing.value = true;
    paiementErrors.value = {};
    router.post(
        `/logistique/commissions/livreurs/${selectedLivreur.value.livreur_id}/paiements`,
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

// ── Statuts ────────────────────────────────────────────────────────────────────

interface StatutBadge {
    label: string;
    dotClass: string;
}

function livreurStatuts(l: LivreurRow): StatutBadge[] {
    const badges: StatutBadge[] = [];
    if (l.impaye > 0) badges.push({ label: 'Impayé', dotClass: 'bg-red-500' });
    if (l.paye > 0) badges.push({ label: 'Payé', dotClass: 'bg-emerald-500' });
    return badges;
}

// ── Formatage ─────────────────────────────────────────────────────────────────

function formatGNF(val: number | null | undefined): string {
    const n = Number(val ?? 0);
    return new Intl.NumberFormat('fr-FR').format(isNaN(n) ? 0 : n) + ' GNF';
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
</script>

<template>
    <Head title="Commissions logistiques" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-6">
            <!-- ── En-tête ───────────────────────────────────────────────────── -->
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Commissions logistiques
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ kpis.nb_livreurs }} livreur{{
                            kpis.nb_livreurs !== 1 ? 's' : ''
                        }}
                        avec commissions
                    </p>
                </div>
            </div>

            <!-- ── KPIs ──────────────────────────────────────────────────────── -->
            <div class="grid grid-cols-3 gap-3">
                <div
                    class="rounded-lg border bg-card px-3 py-3 text-center sm:px-4"
                >
                    <p
                        class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        Total cumulé
                    </p>
                    <p
                        class="mt-0.5 text-sm font-semibold tabular-nums sm:text-base"
                    >
                        {{ formatGNF(kpiTotalCumule) }}
                    </p>
                </div>
                <div
                    class="rounded-lg border bg-card px-3 py-3 text-center sm:px-4"
                    :class="
                        kpis.total_impaye > 0
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
                            kpis.total_impaye > 0
                                ? 'text-amber-600 dark:text-amber-400'
                                : 'text-foreground'
                        "
                    >
                        {{ formatGNF(kpis.total_impaye) }}
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
                        {{ formatGNF(kpis.total_paye) }}
                    </p>
                </div>
            </div>

            <!-- ── Filtres ────────────────────────────────────────────────────── -->
            <DataFilters
                url="/logistique/commissions"
                :values="filterValues"
                :fields="filterFields"
                :result-count="livreurs.length"
                search-placeholder="Nom, téléphone, véhicule, immatriculation, montant, statut…"
                search-key="search"
                v-model:search="search"
            />

            <!-- ── Tableau livreurs ──────────────────────────────────────────── -->
            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <table v-if="livreurs.length > 0" class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40">
                            <th
                                class="px-4 py-3 text-left font-medium text-muted-foreground"
                            >
                                Livreur
                            </th>
                            <th
                                class="px-4 py-3 text-left font-medium text-muted-foreground"
                            >
                                Véhicule(s)
                            </th>
                            <th
                                class="px-4 py-3 text-left font-medium text-muted-foreground"
                            >
                                Statut
                            </th>
                            <th
                                class="px-4 py-3 text-right font-medium text-muted-foreground"
                            >
                                Total cumulé
                            </th>
                            <th
                                class="px-4 py-3 text-right font-medium text-muted-foreground"
                            >
                                Reste à payer
                            </th>
                            <th
                                class="px-4 py-3 text-right font-medium text-muted-foreground"
                            >
                                Déjà payé
                            </th>
                            <th class="w-10 px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="l in livreurs"
                            :key="l.livreur_id"
                            class="transition-colors hover:bg-muted/10"
                        >
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <User
                                        class="h-4 w-4 shrink-0 text-muted-foreground"
                                    />
                                    <div>
                                        <p class="font-medium">{{ l.nom }}</p>
                                        <p
                                            v-if="l.telephone"
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ formatPhone(l.telephone) }}
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div
                                    v-if="l.vehicules"
                                    class="flex items-center gap-1.5 text-sm text-muted-foreground"
                                >
                                    <Truck class="h-3.5 w-3.5 shrink-0" />
                                    <span>{{ l.vehicules }}</span>
                                </div>
                                <span
                                    v-else
                                    class="text-xs text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-col gap-1">
                                    <StatusDot
                                        v-for="s in livreurStatuts(l)"
                                        :key="s.label"
                                        :label="s.label"
                                        :dot-class="s.dotClass"
                                        class="text-xs text-muted-foreground"
                                    />
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(l.impaye + l.paye) }}
                            </td>
                            <td
                                class="px-4 py-3 text-right font-semibold tabular-nums"
                                :class="
                                    l.impaye > 0
                                        ? 'text-amber-600 dark:text-amber-400'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{ formatGNF(l.impaye) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(l.paye) }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="h-7 w-7"
                                        >
                                            <MoreHorizontal class="h-4 w-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem as-child>
                                            <Link
                                                :href="`/logistique/commissions/livreurs/${l.livreur_id}`"
                                                class="flex w-full cursor-pointer items-center"
                                            >
                                                Détail
                                            </Link>
                                        </DropdownMenuItem>
                                        <template
                                            v-if="can_payer && l.impaye > 0"
                                        >
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                class="cursor-pointer"
                                                @click="openPaiement(l)"
                                            >
                                                <HandCoins
                                                    class="mr-2 h-4 w-4"
                                                />
                                                Payer
                                            </DropdownMenuItem>
                                        </template>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div
                    v-else
                    class="flex flex-col items-center gap-3 py-16 text-muted-foreground"
                >
                    <HandCoins class="h-12 w-12 opacity-30" />
                    <p class="text-sm">
                        Aucune commission trouvée pour ce filtre.
                    </p>
                </div>
            </div>
        </div>
    </AppLayout>

    <!-- ── Dialog paiement ───────────────────────────────────────────────────── -->
    <PaymentDialogCompact
        v-model:visible="showPaiementDialog"
        :title="selectedLivreur ? `Payer — ${selectedLivreur.nom}` : 'Payer'"
        :solde="selectedLivreur?.impaye ?? 0"
        :processing="paiementProcessing"
        :errors="paiementErrors"
        @submit="handlePaiementSubmit"
    />
</template>
