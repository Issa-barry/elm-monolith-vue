<script setup lang="ts">
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
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, BadgeCheck, Gift, History, MoreVertical, Search, ShieldCheck } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Dialog from 'primevue/dialog';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import { computed, ref } from 'vue';

interface ModePaiementOption { value: string; label: string }
interface ClientRef { id: number; nom_complet: string }

interface Versement {
    id: number;
    montant: number;
    mode_paiement: string;
    date_versement: string;
    note: string | null;
}

interface Transaction {
    id: number;
    client: { id: number; nom_complet: string; telephone: string | null } | null;
    montant: number;
    montant_verse: number;
    montant_restant: number;
    statut: 'en_attente' | 'valide' | 'partiel' | 'verse';
    note: string | null;
    valide_le: string | null;
    verse_le: string | null;
    created_at: string;
    versements: Versement[];
}

const props = defineProps<{
    transactions: Transaction[];
    clients: ClientRef[];
    filters: { statut?: string; client_id?: string };
    can_valider: boolean;
    modes_paiement: ModePaiementOption[];
}>();

const page = usePage();
const flashSuccess = computed(() => (page.props as any).flash?.success as string | undefined);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Cashback clients', href: '/cashback' },
];

// ── Filtres ───────────────────────────────────────────────────────────────────
const statutOptions = [
    { label: 'Tous', value: 'tous' },
    { label: 'En attente', value: 'en_attente' },
    { label: 'Validé', value: 'valide' },
    { label: 'Partiel', value: 'partiel' },
    { label: 'Versé', value: 'verse' },
];
const localStatut = ref(props.filters.statut ?? 'tous');
const localClientId = ref<number | ''>(props.filters.client_id ? Number(props.filters.client_id) : '');
const search = ref('');

function applyFilters() {
    router.get('/cashback', {
        statut: localStatut.value !== 'tous' ? localStatut.value : undefined,
        client_id: localClientId.value !== '' ? localClientId.value : undefined,
    }, { preserveState: true, replace: true });
}

const filtered = computed(() => {
    const q = search.value.toLowerCase();
    if (!q) return props.transactions;
    return props.transactions.filter(
        (t) => t.client?.nom_complet.toLowerCase().includes(q) || t.client?.telephone?.includes(q),
    );
});

// ── KPI cards ─────────────────────────────────────────────────────────────────
const kpi = computed(() => {
    const all = props.transactions;
    const enAttente = all.filter((t) => t.statut === 'en_attente');
    const enCours   = all.filter((t) => t.statut === 'valide' || t.statut === 'partiel');
    return {
        total:           all.reduce((s, t) => s + t.montant, 0),
        nb_total:        all.length,
        montant_attente: enAttente.reduce((s, t) => s + t.montant, 0),
        nb_attente:      enAttente.length,
        montant_cours:   enCours.reduce((s, t) => s + t.montant_restant, 0),
        nb_cours:        enCours.length,
        // Total réellement décaissé (toutes transactions, y compris partiels)
        montant_verse:   all.reduce((s, t) => s + t.montant_verse, 0),
        nb_verse:        all.filter((t) => t.statut === 'verse').length,
    };
});

// ── Statut config ─────────────────────────────────────────────────────────────
const statutConfig: Record<string, { label: string; dot: string }> = {
    en_attente: { label: 'En attente', dot: 'bg-amber-500' },
    valide:     { label: 'Validé',     dot: 'bg-blue-500' },
    partiel:    { label: 'Partiel',    dot: 'bg-orange-500' },
    verse:      { label: 'Versé',      dot: 'bg-emerald-500' },
};

// ── Dialog valider ────────────────────────────────────────────────────────────
const validerVisible = ref(false);
const validerTarget  = ref<Transaction | null>(null);
const validerForm    = useForm({ note: '' });

function openValider(t: Transaction) {
    validerTarget.value = t;
    validerForm.note = '';
    validerForm.clearErrors();
    validerVisible.value = true;
}
function submitValider() {
    if (!validerTarget.value) return;
    validerForm.patch(`/cashback/${validerTarget.value.id}/valider`, {
        preserveScroll: true,
        onSuccess: () => { validerVisible.value = false; },
    });
}

// ── Dialog verser ─────────────────────────────────────────────────────────────
const verserVisible = ref(false);
const verserTarget  = ref<Transaction | null>(null);
const today = new Date().toISOString().slice(0, 10);
const verserForm = useForm({ montant: 0, mode_paiement: 'especes', date_versement: today, note: '' });

function openVerser(t: Transaction) {
    verserTarget.value = t;
    verserForm.montant         = t.montant_restant;
    verserForm.mode_paiement   = 'especes';
    verserForm.date_versement  = today;
    verserForm.note            = '';
    verserForm.clearErrors();
    verserVisible.value = true;
}
function submitVerser() {
    if (!verserTarget.value) return;
    verserForm.patch(`/cashback/${verserTarget.value.id}/verser`, {
        preserveScroll: true,
        onSuccess: () => { verserVisible.value = false; },
    });
}

// ── Dialog historique ─────────────────────────────────────────────────────────
const historyVisible = ref(false);
const historyTarget  = ref<Transaction | null>(null);

function openHistory(t: Transaction) {
    historyTarget.value = t;
    historyVisible.value = true;
}

// ── Formatage ─────────────────────────────────────────────────────────────────
function formatGNF(v: number) { return new Intl.NumberFormat('fr-FR').format(v) + ' GNF'; }
function formatDate(d: string | null) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' });
}
function formatPhone(phone: string | null): string {
    if (!phone) return '';
    const cleaned = phone.replace(/\s+/g, '');
    // Format guinéen : +224 XXX XXX XXX
    if (cleaned.startsWith('+224') && cleaned.length === 13) {
        return `+224 ${cleaned.slice(4, 7)} ${cleaned.slice(7, 10)} ${cleaned.slice(10)}`;
    }
    // Numéro local 9 chiffres : XXX XXX XXX
    if (/^\d{9}$/.test(cleaned)) {
        return `${cleaned.slice(0, 3)} ${cleaned.slice(3, 6)} ${cleaned.slice(6)}`;
    }
    return phone;
}
</script>

<template>
    <Head title="Cashback clients" />
    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">

        <!-- ── MOBILE VIEW ──────────────────────────────────────────────────── -->
        <div class="flex flex-col sm:hidden">
            <!-- Sticky header -->
            <div class="sticky top-0 z-10 flex items-center justify-between border-b bg-background px-4 py-3">
                <Link href="/dashboard" class="flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground hover:text-foreground">
                    <ArrowLeft class="h-5 w-5" />
                </Link>
                <span class="text-base font-semibold">Cashback</span>
                <div class="w-8" />
            </div>

            <!-- Flash -->
            <div v-if="flashSuccess" class="mx-4 mt-3 flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-xs text-green-800">
                <BadgeCheck class="h-3.5 w-3.5 shrink-0" />
                {{ flashSuccess }}
            </div>

            <!-- KPI cards -->
            <div class="grid grid-cols-2 gap-3 p-4">
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground">Total</p>
                    <p class="mt-1 text-lg font-bold tabular-nums">{{ formatGNF(kpi.total) }}</p>
                    <p class="text-xs text-muted-foreground">{{ kpi.nb_total }} transaction{{ kpi.nb_total !== 1 ? 's' : '' }}</p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground">En attente</p>
                    <p class="mt-1 text-lg font-bold tabular-nums">{{ formatGNF(kpi.montant_attente) }}</p>
                    <p class="text-xs text-muted-foreground">{{ kpi.nb_attente }} transaction{{ kpi.nb_attente !== 1 ? 's' : '' }}</p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground">Restant à verser</p>
                    <p class="mt-1 text-lg font-bold tabular-nums">{{ formatGNF(kpi.montant_cours) }}</p>
                    <p class="text-xs text-muted-foreground">{{ kpi.nb_cours }} transaction{{ kpi.nb_cours !== 1 ? 's' : '' }}</p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground">Versé</p>
                    <p class="mt-1 text-lg font-bold tabular-nums">{{ formatGNF(kpi.montant_verse) }}</p>
                    <p class="text-xs text-muted-foreground">{{ kpi.nb_verse }} transaction{{ kpi.nb_verse !== 1 ? 's' : '' }}</p>
                </div>
            </div>

            <!-- Search -->
            <div class="border-t border-b px-4 py-2">
                <div class="relative">
                    <Search class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Client, téléphone…"
                        class="h-9 w-full rounded-md border border-input bg-background pr-3 pl-8 text-sm placeholder:text-muted-foreground focus:ring-1 focus:ring-ring focus:outline-none"
                    />
                </div>
            </div>

            <!-- Card list -->
            <div class="divide-y">
                <div v-for="t in filtered" :key="t.id" class="flex items-start justify-between gap-3 px-4 py-3">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ t.client?.nom_complet ?? '—' }}</p>
                        <p v-if="t.client?.telephone" class="mt-0.5 text-xs text-muted-foreground">{{ formatPhone(t.client.telephone) }}</p>
                        <p class="mt-1 text-sm font-semibold tabular-nums">{{ formatGNF(t.montant) }}</p>
                        <p v-if="t.montant_restant > 0" class="text-xs font-semibold text-orange-600 tabular-nums">
                            Restant : {{ formatGNF(t.montant_restant) }}
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-2">
                        <StatusDot
                            :label="statutConfig[t.statut]?.label ?? t.statut"
                            :dot-class="statutConfig[t.statut]?.dot"
                            class="text-xs text-muted-foreground"
                        />
                        <span class="text-xs text-muted-foreground tabular-nums">{{ formatDate(t.created_at) }}</span>
                        <Button
                            v-if="t.statut === 'en_attente' && can_valider"
                            size="sm"
                            variant="outline"
                            class="h-7 border-blue-300 text-xs text-blue-700 hover:bg-blue-50"
                            @click="openValider(t)"
                        >
                            <ShieldCheck class="mr-1 h-3.5 w-3.5" />
                            Valider
                        </Button>
                        <Button
                            v-else-if="t.statut === 'valide' || t.statut === 'partiel'"
                            size="sm"
                            variant="outline"
                            class="h-7 border-emerald-300 text-xs text-emerald-700 hover:bg-emerald-50"
                            @click="openVerser(t)"
                        >
                            <Gift class="mr-1 h-3.5 w-3.5" />
                            Verser
                        </Button>
                        <Button
                            v-if="t.versements.length > 0"
                            size="sm"
                            variant="ghost"
                            class="h-7 text-xs text-muted-foreground"
                            @click="openHistory(t)"
                        >
                            <History class="mr-1 h-3.5 w-3.5" />
                            Historique
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Empty state -->
            <div v-if="filtered.length === 0" class="py-16 text-center text-sm text-muted-foreground">
                Aucun cashback trouvé.
            </div>
        </div>

        <!-- ── DESKTOP VIEW ─────────────────────────────────────────────────── -->
        <div class="hidden flex-col gap-6 p-6 sm:flex">

            <!-- En-tête -->
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Cashback clients</h1>
                <p class="mt-1 text-sm text-muted-foreground">Gestion des cashbacks à verser aux clients.</p>
            </div>

            <!-- Flash -->
            <div v-if="flashSuccess" class="flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                <BadgeCheck class="h-4 w-4 shrink-0" />
                {{ flashSuccess }}
            </div>

            <!-- KPI cards -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Total</p>
                    <p class="mt-2 text-2xl font-bold tabular-nums">{{ formatGNF(kpi.total) }}</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ kpi.nb_total }} transaction{{ kpi.nb_total !== 1 ? 's' : '' }}</p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">En attente</p>
                    <p class="mt-2 text-2xl font-bold tabular-nums">{{ formatGNF(kpi.montant_attente) }}</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ kpi.nb_attente }} transaction{{ kpi.nb_attente !== 1 ? 's' : '' }}</p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Restant à verser</p>
                    <p class="mt-2 text-2xl font-bold tabular-nums">{{ formatGNF(kpi.montant_cours) }}</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ kpi.nb_cours }} transaction{{ kpi.nb_cours !== 1 ? 's' : '' }}</p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Versé</p>
                    <p class="mt-2 text-2xl font-bold tabular-nums">{{ formatGNF(kpi.montant_verse) }}</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ kpi.nb_verse }} transaction{{ kpi.nb_verse !== 1 ? 's' : '' }}</p>
                </div>
            </div>

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="filtered"
                    :paginator="filtered.length > 20"
                    :rows="20"
                    data-key="id"
                    striped-rows
                    removable-sort
                    class="text-sm"
                    :pt="{
                        root: { class: 'w-full' },
                        header: { class: 'border-b bg-muted/30 px-4 py-3' },
                        tbody: { class: 'divide-y' },
                    }"
                >
                    <template #header>
                        <div class="flex items-center gap-3">
                            <IconField class="max-w-sm flex-1">
                                <InputIcon class="pointer-events-none">
                                    <Search class="h-4 w-4 text-muted-foreground" />
                                </InputIcon>
                                <InputText v-model="search" placeholder="Client, téléphone…" class="w-full text-sm" />
                            </IconField>
                            <Select
                                v-model="localStatut"
                                :options="statutOptions"
                                option-label="label"
                                option-value="value"
                                class="w-36"
                                @update:model-value="applyFilters"
                            />
                            <Select
                                v-model="localClientId"
                                :options="[{ id: '', nom_complet: 'Tous les clients' }, ...clients]"
                                option-label="nom_complet"
                                option-value="id"
                                placeholder="Tous les clients"
                                class="w-48"
                                show-clear
                                @update:model-value="applyFilters"
                            />
                            <span class="text-xs text-muted-foreground">
                                {{ filtered.length }} résultat{{ filtered.length !== 1 ? 's' : '' }}
                            </span>
                        </div>
                    </template>

                    <!-- Client -->
                    <Column field="client.nom_complet" header="Client" sortable style="min-width: 180px">
                        <template #body="{ data }">
                            <div class="font-medium">{{ data.client?.nom_complet ?? '—' }}</div>
                            <div v-if="data.client?.telephone" class="text-xs text-muted-foreground">{{ formatPhone(data.client.telephone) }}</div>
                        </template>
                    </Column>

                    <!-- Montant -->
                    <Column field="montant" header="Montant" sortable style="width: 150px">
                        <template #body="{ data }">
                            <span class="tabular-nums">{{ formatGNF(data.montant) }}</span>
                        </template>
                    </Column>

                    <!-- Versé -->
                    <Column field="montant_verse" header="Versé" sortable style="width: 140px">
                        <template #body="{ data }">
                            <span class="text-muted-foreground tabular-nums">{{ formatGNF(data.montant_verse) }}</span>
                        </template>
                    </Column>

                    <!-- Restant -->
                    <Column field="montant_restant" header="Restant" sortable style="width: 140px">
                        <template #body="{ data }">
                            <span class="text-muted-foreground tabular-nums">
                                {{ data.montant_restant > 0 ? formatGNF(data.montant_restant) : '—' }}
                            </span>
                        </template>
                    </Column>

                    <!-- Statut -->
                    <Column field="statut" header="Statut" sortable style="width: 130px">
                        <template #body="{ data }">
                            <StatusDot
                                :label="statutConfig[data.statut]?.label ?? data.statut"
                                :dot-class="statutConfig[data.statut]?.dot"
                                class="text-muted-foreground"
                            />
                        </template>
                    </Column>

                    <!-- Date -->
                    <Column field="created_at" header="Date" sortable style="width: 110px">
                        <template #body="{ data }">
                            <span class="text-xs text-muted-foreground tabular-nums">{{ formatDate(data.created_at) }}</span>
                        </template>
                    </Column>

                    <!-- Actions -->
                    <Column header="" style="width: 56px">
                        <template #body="{ data }">
                            <div class="flex justify-end">
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button variant="ghost" size="icon" class="h-8 w-8">
                                            <MoreVertical class="h-4 w-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end" class="w-48">
                                        <DropdownMenuItem
                                            v-if="data.versements.length > 0"
                                            class="cursor-pointer"
                                            @click="openHistory(data)"
                                        >
                                            <History class="h-4 w-4" />
                                            Historique
                                        </DropdownMenuItem>
                                        <template v-if="data.statut === 'en_attente' && can_valider">
                                            <DropdownMenuSeparator v-if="data.versements.length > 0" />
                                            <DropdownMenuItem class="cursor-pointer" @click="openValider(data)">
                                                <ShieldCheck class="h-4 w-4" />
                                                Valider
                                            </DropdownMenuItem>
                                        </template>
                                        <template v-if="data.statut === 'valide' || data.statut === 'partiel'">
                                            <DropdownMenuSeparator v-if="data.versements.length > 0" />
                                            <DropdownMenuItem class="cursor-pointer" @click="openVerser(data)">
                                                <Gift class="h-4 w-4" />
                                                Verser
                                            </DropdownMenuItem>
                                        </template>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </template>
                    </Column>

                    <template #empty>
                        <div class="py-16 text-center text-sm text-muted-foreground">Aucun cashback trouvé.</div>
                    </template>
                </DataTable>
            </div>
        </div>

        <!-- Dialog — Historique des versements ──────────────────────────────── -->
        <Dialog
            v-model:visible="historyVisible"
            modal
            :header="historyTarget ? `Historique — ${historyTarget.client?.nom_complet ?? ''}` : 'Historique'"
            :style="{ width: '560px' }"
        >
            <div v-if="historyTarget">
                <div v-if="historyTarget.versements.length === 0" class="py-8 text-center text-sm text-muted-foreground">
                    Aucun versement enregistré.
                </div>
                <table v-else class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/30">
                            <th class="px-3 py-2 text-left font-medium text-muted-foreground">Date</th>
                            <th class="px-3 py-2 text-left font-medium text-muted-foreground">Mode</th>
                            <th class="px-3 py-2 text-right font-medium text-muted-foreground">Montant</th>
                            <th class="px-3 py-2 text-left font-medium text-muted-foreground">Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="v in historyTarget.versements" :key="v.id" class="border-b last:border-0">
                            <td class="px-3 py-2 tabular-nums">{{ v.date_versement }}</td>
                            <td class="px-3 py-2 capitalize">{{ v.mode_paiement.replace('_', ' ') }}</td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ formatGNF(v.montant) }}</td>
                            <td class="px-3 py-2 text-muted-foreground">{{ v.note ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Dialog>

        <!-- Dialog — Valider ─────────────────────────────────────────────────── -->
        <Dialog
            v-model:visible="validerVisible"
            modal
            header="Valider le cashback"
            :style="{ width: '440px' }"
        >
            <div v-if="validerTarget" class="flex flex-col gap-4">
                <div class="rounded-lg border bg-muted/30 px-4 py-3 text-sm">
                    <p class="text-muted-foreground">Client</p>
                    <p class="font-medium">{{ validerTarget.client?.nom_complet }}</p>
                    <p class="mt-1 text-muted-foreground">Montant cashback</p>
                    <p class="text-lg font-bold tabular-nums">{{ formatGNF(validerTarget.montant) }}</p>
                </div>
                <p class="text-xs text-blue-700 bg-blue-50 rounded-lg px-3 py-2 border border-blue-100">
                    En validant, vous autorisez le versement (total ou partiel) de ce cashback.
                </p>
                <div>
                    <label class="mb-1 block text-sm font-medium">Note (facultative)</label>
                    <textarea
                        v-model="validerForm.note"
                        rows="2"
                        class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                        placeholder="Ex : vérifié avec le bon de commande #…"
                    />
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button variant="outline" @click="validerVisible = false">Annuler</Button>
                    <Button :disabled="validerForm.processing" @click="submitValider">
                        <ShieldCheck class="h-4 w-4" />
                        {{ validerForm.processing ? 'En cours…' : 'Confirmer la validation' }}
                    </Button>
                </div>
            </template>
        </Dialog>

        <!-- Dialog — Verser ─────────────────────────────────────────────────── -->
        <Dialog
            v-model:visible="verserVisible"
            modal
            header="Versement cashback"
            :style="{ width: '440px' }"
        >
            <div v-if="verserTarget" class="flex flex-col gap-4">
                <!-- Récapitulatif -->
                <div class="rounded-lg border bg-muted/30 px-4 py-3 text-sm">
                    <p class="text-muted-foreground">Client</p>
                    <p class="font-medium">{{ verserTarget.client?.nom_complet }}</p>
                    <div class="mt-2 flex gap-6 text-xs">
                        <div>
                            <p class="text-muted-foreground">Total</p>
                            <p class="font-semibold tabular-nums">{{ formatGNF(verserTarget.montant) }}</p>
                        </div>
                        <div>
                            <p class="text-muted-foreground">Déjà versé</p>
                            <p class="font-semibold tabular-nums">{{ formatGNF(verserTarget.montant_verse) }}</p>
                        </div>
                        <div>
                            <p class="text-muted-foreground">Restant</p>
                            <p class="font-semibold tabular-nums text-orange-600">{{ formatGNF(verserTarget.montant_restant) }}</p>
                        </div>
                    </div>
                </div>

                <!-- Montant -->
                <div>
                    <label class="mb-1 block text-sm font-medium">Montant à verser (GNF) <span class="text-red-500">*</span></label>
                    <input
                        v-model.number="verserForm.montant"
                        type="number"
                        :min="1"
                        :max="verserTarget.montant_restant"
                        class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                    />
                    <p v-if="verserForm.errors.montant" class="mt-1 text-xs text-red-500">{{ verserForm.errors.montant }}</p>
                </div>

                <!-- Mode de paiement -->
                <div>
                    <label class="mb-1 block text-sm font-medium">Mode de paiement <span class="text-red-500">*</span></label>
                    <Select v-model="verserForm.mode_paiement" :options="modes_paiement" option-label="label" option-value="value" placeholder="Choisir" class="w-full" />
                    <p v-if="verserForm.errors.mode_paiement" class="mt-1 text-xs text-red-500">{{ verserForm.errors.mode_paiement }}</p>
                </div>

                <!-- Date -->
                <div>
                    <label class="mb-1 block text-sm font-medium">Date du versement <span class="text-red-500">*</span></label>
                    <input v-model="verserForm.date_versement" type="date" class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring" />
                    <p v-if="verserForm.errors.date_versement" class="mt-1 text-xs text-red-500">{{ verserForm.errors.date_versement }}</p>
                </div>

                <!-- Note -->
                <div>
                    <label class="mb-1 block text-sm font-medium">Note (facultative)</label>
                    <textarea
                        v-model="verserForm.note"
                        rows="2"
                        class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                        placeholder="Ex : remise en main propre…"
                    />
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button variant="outline" @click="verserVisible = false">Annuler</Button>
                    <Button :disabled="verserForm.processing" @click="submitVerser">
                        <Gift class="h-4 w-4" />
                        {{ verserForm.processing ? 'En cours…' : 'Confirmer le versement' }}
                    </Button>
                </div>
            </template>
        </Dialog>
    </AppLayout>
</template>
