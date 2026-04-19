<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { HandCoins, MoreHorizontal, Truck, User } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import PvDropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { computed, reactive, ref, watch } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────

interface LivreurRow {
    livreur_id: number;
    nom: string;
    telephone: string | null;
    vehicules: string | null;
    pending: number;
    available: number;
    paid: number;
}

interface Kpis {
    nb_livreurs: number;
    total_pending: number;
    total_available: number;
    total_paid: number;
}

interface SelectOption {
    value: string | null;
    label: string;
}

// ── Props ─────────────────────────────────────────────────────────────────────

const props = defineProps<{
    livreurs: LivreurRow[];
    kpis: Kpis;
    search: string;
    filtre_statut: string;
    can_payer: boolean;
}>();

// ── Breadcrumbs ───────────────────────────────────────────────────────────────

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Commissions logistiques', href: '/logistique/commissions' },
];

// ── Filtres ───────────────────────────────────────────────────────────────────

const searchVal = ref(props.search ?? '');
const statutFiltre = ref<string | null>(props.filtre_statut || null);

const STATUT_OPTIONS: SelectOption[] = [
    { value: null, label: 'Tous les statuts' },
    { value: 'available', label: 'Disponible à payer' },
    { value: 'pending', label: 'En attente de déblocage' },
    { value: 'paid', label: 'Entièrement versé' },
];

function appliquerFiltres() {
    router.get(
        '/logistique/commissions',
        {
            search: searchVal.value || undefined,
            statut: statutFiltre.value ?? undefined,
        },
        { preserveState: true, replace: true },
    );
}

let searchTimeout: ReturnType<typeof setTimeout> | null = null;
watch(searchVal, () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(appliquerFiltres, 300);
});
watch(statutFiltre, appliquerFiltres);

// ── KPIs calculés ─────────────────────────────────────────────────────────────

const kpiTotalCumule = computed(
    () =>
        props.kpis.total_pending +
        props.kpis.total_available +
        props.kpis.total_paid,
);

// ── Paiement ──────────────────────────────────────────────────────────────────

const MODES_PAIEMENT = [
    { value: 'especes', label: 'Espèces' },
    { value: 'virement', label: 'Virement' },
    { value: 'cheque', label: 'Chèque' },
    { value: 'mobile_money', label: 'Mobile Money' },
];

const showPaiementDialog = ref(false);
const selectedLivreur = ref<LivreurRow | null>(null);

interface PaiementForm {
    montant: number | null;
    mode_paiement: string;
    processing: boolean;
    errors: Record<string, string>;
}

const paiementForm = reactive<PaiementForm>({
    montant: null,
    mode_paiement: 'especes',
    processing: false,
    errors: {},
});

function openPaiement(livreur: LivreurRow) {
    selectedLivreur.value = livreur;
    paiementForm.montant = livreur.available > 0 ? livreur.available : null;
    paiementForm.mode_paiement = 'especes';
    paiementForm.processing = false;
    paiementForm.errors = {};
    showPaiementDialog.value = true;
}

function submitPaiement() {
    if (
        !paiementForm.montant ||
        paiementForm.montant <= 0 ||
        !selectedLivreur.value
    )
        return;
    paiementForm.processing = true;
    paiementForm.errors = {};
    router.post(
        `/logistique/commissions/livreurs/${selectedLivreur.value.livreur_id}/paiements`,
        {
            montant: paiementForm.montant,
            mode_paiement: paiementForm.mode_paiement,
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
                        kpis.total_available > 0
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
                            kpis.total_available > 0
                                ? 'text-amber-600 dark:text-amber-400'
                                : 'text-foreground'
                        "
                    >
                        {{ formatGNF(kpis.total_available) }}
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
                        {{ formatGNF(kpis.total_paid) }}
                    </p>
                </div>
            </div>

            <!-- ── Filtres ────────────────────────────────────────────────────── -->
            <div class="flex flex-wrap items-center gap-3">
                <InputText
                    v-model="searchVal"
                    placeholder="Rechercher un livreur…"
                    class="w-64 text-sm"
                />
                <PvDropdown
                    :options="STATUT_OPTIONS"
                    option-label="label"
                    option-value="value"
                    :model-value="statutFiltre"
                    placeholder="Tous les statuts"
                    class="w-52 text-sm"
                    @change="(e) => (statutFiltre = e.value)"
                />
                <span class="text-xs text-muted-foreground">
                    {{ livreurs.length }} résultat{{
                        livreurs.length !== 1 ? 's' : ''
                    }}
                </span>
            </div>

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
                                <span v-else class="text-xs text-muted-foreground">—</span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{
                                    formatGNF(l.pending + l.available + l.paid)
                                }}
                            </td>
                            <td
                                class="px-4 py-3 text-right font-semibold tabular-nums"
                                :class="
                                    l.available > 0
                                        ? 'text-amber-600 dark:text-amber-400'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{ formatGNF(l.available) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(l.paid) }}
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
                                            v-if="can_payer && l.available > 0"
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
    <Dialog
        v-model:visible="showPaiementDialog"
        modal
        :header="selectedLivreur ? `Payer — ${selectedLivreur.nom}` : 'Payer'"
        :style="{ width: '420px' }"
        :draggable="false"
    >
        <div v-if="selectedLivreur" class="space-y-4 py-2">
            <div
                class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300"
            >
                Solde à payer :
                <strong>{{ formatGNF(selectedLivreur.available) }}</strong>
            </div>
            <div>
                <Label class="mb-1.5 block text-sm">Montant (GNF)</Label>
                <InputNumber
                    v-model="paiementForm.montant"
                    :min="1"
                    :max="selectedLivreur.available"
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
                <PvDropdown
                    v-model="paiementForm.mode_paiement"
                    :options="MODES_PAIEMENT"
                    option-label="label"
                    option-value="value"
                    class="w-full"
                />
            </div>
        </div>
        <template #footer>
            <Button
                variant="outline"
                :disabled="paiementForm.processing"
                @click="showPaiementDialog = false"
            >
                Annuler
            </Button>
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
                Confirmer le paiement
            </Button>
        </template>
    </Dialog>
</template>
