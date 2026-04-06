<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { formatPhoneDisplay } from '@/lib/utils';
import {
    ArrowLeft,
    ChevronRight,
    Search,
    Truck,
    User,
} from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import { computed, ref } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────

interface PartItem {
    id: number;
    commission_id: number;
    commande_id: number;
    commande_reference: string | null;
    site_nom: string | null;
    vehicule_nom: string | null;
    immatriculation: string | null;
    equipe_nom: string | null;
    livreur_principal_telephone: string | null;
    type_beneficiaire: 'livreur' | 'proprietaire';
    beneficiaire_nom: string;
    taux_commission: number;
    montant_brut: number;
    frais_supplementaires: number;
    montant_net: number;
    montant_verse: number;
    montant_restant: number;
    statut: string;
    statut_label: string;
    created_at: string;
}

interface Totaux {
    total_commission: number;
    total_a_verser: number;
    nb_en_attente: number;
    montant_en_attente: number;
    nb_partielles: number;
    montant_partielles: number;
    nb_versees: number;
    montant_versees: number;
}

// ── Props ─────────────────────────────────────────────────────────────────────

const props = defineProps<{
    parts: PartItem[];
    totaux: Totaux;
    periode: string;
    tab: 'livreurs' | 'proprietaires';
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Commissions', href: '/commissions' },
];

// ── Navigation ────────────────────────────────────────────────────────────────

function setTab(t: 'livreurs' | 'proprietaires') {
    const defaultPeriode = t === 'proprietaires' ? 'month' : 'week';
    router.get('/commissions', { tab: t, periode: defaultPeriode }, { preserveScroll: false, replace: true });
}

function setPeriode(p: string) {
    router.get('/commissions', { tab: props.tab, periode: p }, { preserveScroll: true, replace: true });
}

// ── Filtres locaux ────────────────────────────────────────────────────────────

const filtresStatut = [
    { value: 'tous', label: 'Tous statuts' },
    { value: 'en_attente', label: 'En attente' },
    { value: 'partielle', label: 'Partielles' },
    { value: 'versee', label: 'Versées' },
    { value: 'annulee', label: 'Annulées' },
];

const periodes = [
    { value: 'today', label: "Aujourd'hui" },
    { value: 'week', label: 'Cette semaine' },
    { value: 'month', label: 'Ce mois' },
    { value: 'all', label: 'Tout' },
];

const filtreStatut = ref('tous');
const search = ref('');
const mobileSearch = ref('');

// ── Filtrage ──────────────────────────────────────────────────────────────────

function filterList(list: PartItem[], q: string): PartItem[] {
    if (filtreStatut.value !== 'tous') {
        list = list.filter((p) => p.statut === filtreStatut.value);
    }
    const query = q.toLowerCase().trim();
    if (query) {
        list = list.filter(
            (p) =>
                (p.commande_reference && p.commande_reference.toLowerCase().includes(query)) ||
                (p.vehicule_nom && p.vehicule_nom.toLowerCase().includes(query)) ||
                (p.immatriculation && p.immatriculation.toLowerCase().includes(query)) ||
                (p.equipe_nom && p.equipe_nom.toLowerCase().includes(query)) ||
                p.beneficiaire_nom.toLowerCase().includes(query) ||
                (p.site_nom && p.site_nom.toLowerCase().includes(query)),
        );
    }
    return list;
}

const partsFiltrees = computed(() => filterList([...props.parts], search.value));
const mobileFiltrees = computed(() => filterList([...props.parts], mobileSearch.value));

// ── KPI locaux (calculés depuis les parts filtrées) ───────────────────────────

const kpi = computed(() => {
    const list = partsFiltrees.value.filter((p) => p.statut !== 'annulee');
    const versees = list.filter((p) => p.statut === 'versee');
    const enAttente = list.filter((p) => p.statut === 'en_attente');
    return {
        total_brut:       list.reduce((s, p) => s + p.montant_brut, 0),
        total_verse:      list.reduce((s, p) => s + p.montant_verse, 0),
        total_a_verser:   list.filter((p) => p.statut !== 'versee').reduce((s, p) => s + p.montant_restant, 0),
        nb_parts:         list.length,
        nb_en_attente:    enAttente.length,
        montant_en_attente: enAttente.reduce((s, p) => s + p.montant_net, 0),
        nb_versees:       versees.length,
    };
});

// ── Statut couleur ────────────────────────────────────────────────────────────

const statutDotColor: Record<string, string> = {
    en_attente: 'bg-amber-500',
    partielle:  'bg-blue-500',
    versee:     'bg-emerald-500',
    annulee:    'bg-zinc-400 dark:bg-zinc-500',
};

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

// ── Labels ────────────────────────────────────────────────────────────────────

const tabLabel = computed(() =>
    props.tab === 'livreurs' ? 'Livreurs' : 'Propriétaires',
);
</script>

<template>
    <Head title="Commissions" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">

        <!-- ══════════════════════ MOBILE ══════════════════════════════════════ -->
        <div class="flex flex-col sm:hidden">

            <!-- Sticky header -->
            <div class="sticky top-0 z-10 border-b bg-background">
                <div class="flex items-center justify-between px-4 py-3">
                    <Link href="/dashboard" class="flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground">
                        <ArrowLeft class="h-5 w-5" />
                    </Link>
                    <span class="text-base font-semibold">Commissions</span>
                    <div class="w-8" />
                </div>

                <!-- Tabs mobile -->
                <div class="flex border-t">
                    <button
                        class="flex flex-1 items-center justify-center gap-1.5 py-2.5 text-xs font-semibold transition-colors"
                        :class="tab === 'livreurs'
                            ? 'border-b-2 border-primary text-primary'
                            : 'border-b-2 border-transparent text-muted-foreground'"
                        @click="setTab('livreurs')"
                    >
                        <Truck class="h-3.5 w-3.5" />
                        Livreurs (Hebdo)
                    </button>
                    <button
                        class="flex flex-1 items-center justify-center gap-1.5 py-2.5 text-xs font-semibold transition-colors"
                        :class="tab === 'proprietaires'
                            ? 'border-b-2 border-primary text-primary'
                            : 'border-b-2 border-transparent text-muted-foreground'"
                        @click="setTab('proprietaires')"
                    >
                        <User class="h-3.5 w-3.5" />
                        Propriétaires (Mensuel)
                    </button>
                </div>
            </div>

            <!-- KPI mobile -->
            <div class="grid grid-cols-2 gap-3 p-4">
                <div class="rounded-xl border bg-card p-3 shadow-sm">
                    <p class="text-xs text-muted-foreground">Restant à verser</p>
                    <p class="mt-1 text-base font-bold text-amber-600 tabular-nums dark:text-amber-400">{{ formatGNF(kpi.total_a_verser) }}</p>
                </div>
                <div class="rounded-xl border bg-card p-3 shadow-sm">
                    <p class="text-xs text-muted-foreground">En attente</p>
                    <p class="mt-1 text-base font-bold text-amber-600 tabular-nums dark:text-amber-400">{{ formatGNF(kpi.montant_en_attente) }}</p>
                    <p class="text-xs text-muted-foreground">{{ kpi.nb_en_attente }} part{{ kpi.nb_en_attente > 1 ? 's' : '' }}</p>
                </div>
                <div class="rounded-xl border bg-card p-3 shadow-sm">
                    <p class="text-xs text-muted-foreground">Total versé</p>
                    <p class="mt-1 text-base font-bold text-emerald-600 tabular-nums dark:text-emerald-400">{{ formatGNF(kpi.total_verse) }}</p>
                    <p class="text-xs text-muted-foreground">{{ kpi.nb_versees }} part{{ kpi.nb_versees > 1 ? 's' : '' }} soldée{{ kpi.nb_versees > 1 ? 's' : '' }}</p>
                </div>
            </div>

            <!-- Search + periode mobile -->
            <div class="space-y-2 border-t px-4 py-3">
                <div class="relative">
                    <Search class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <input
                        v-model="mobileSearch"
                        type="text"
                        placeholder="Commande, livreur, véhicule…"
                        class="h-9 w-full rounded-md border border-input bg-background pr-3 pl-8 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
                    />
                </div>
                <Select
                    :model-value="periode"
                    :options="periodes"
                    option-label="label"
                    option-value="value"
                    class="w-full"
                    @update:model-value="setPeriode($event)"
                />
            </div>

            <!-- Card list mobile -->
            <div class="divide-y">
                <Link
                    v-for="p in mobileFiltrees"
                    :key="p.id"
                    :href="`/commissions/${p.commission_id}`"
                    class="flex items-start justify-between gap-3 px-4 py-3.5 transition-colors active:bg-muted/40"
                >
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold">{{ p.beneficiaire_nom }}</p>
                        <p class="text-xs text-muted-foreground">{{ p.taux_commission }}% · {{ p.vehicule_nom ?? '—' }}</p>
                        <p v-if="p.equipe_nom && tab === 'livreurs'" class="text-xs text-muted-foreground/70">{{ p.equipe_nom }}</p>
                        <p class="mt-1 font-mono text-xs font-semibold text-primary">{{ p.commande_reference ?? '—' }}</p>
                        <p class="mt-0.5 text-sm font-semibold tabular-nums">{{ formatGNF(p.montant_net) }}</p>
                        <p v-if="p.montant_restant > 0" class="text-xs font-semibold text-amber-600 tabular-nums dark:text-amber-400">
                            Restant : {{ formatGNF(p.montant_restant) }}
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-2">
                        <StatusDot
                            :label="p.statut_label"
                            :dot-class="statutDotColor[p.statut] ?? 'bg-zinc-400 dark:bg-zinc-500'"
                            class="text-xs text-muted-foreground"
                        />
                        <span class="text-xs text-muted-foreground tabular-nums">{{ p.created_at }}</span>
                        <ChevronRight class="h-4 w-4 text-muted-foreground" />
                    </div>
                </Link>
            </div>

            <div v-if="mobileFiltrees.length === 0" class="py-16 text-center text-sm text-muted-foreground">
                Aucune commission trouvée.
            </div>
        </div>

        <!-- ══════════════════════ DESKTOP ═════════════════════════════════════ -->
        <div class="hidden w-full space-y-6 p-6 sm:block">

            <!-- En-tête -->
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Commissions</h1>
                <!-- <p class="mt-1 text-sm text-muted-foreground">{{ tabSubtitle }}</p> -->
            </div>

            <!-- KPI cards -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Total {{ tabLabel }}</p>
                    <p class="mt-2 text-2xl font-bold tabular-nums">{{ formatGNF(kpi.total_brut) }}</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ kpi.nb_parts }} part{{ kpi.nb_parts > 1 ? 's' : '' }}</p>
                </div>

                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Restant à verser</p>
                    <p class="mt-2 text-2xl font-bold tabular-nums">{{ formatGNF(kpi.total_a_verser) }}</p>
                </div>

                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">En attente</p>
                    <p class="mt-2 text-2xl font-bold tabular-nums">{{ formatGNF(kpi.montant_en_attente) }}</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ kpi.nb_en_attente }} part{{ kpi.nb_en_attente > 1 ? 's' : '' }}</p>
                </div>

                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Total versé</p>
                    <p class="mt-2 text-2xl font-bold text-emerald-600 tabular-nums dark:text-emerald-400">{{ formatGNF(kpi.total_verse) }}</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ kpi.nb_versees }} part{{ kpi.nb_versees > 1 ? 's' : '' }} soldée{{ kpi.nb_versees > 1 ? 's' : '' }}</p>
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex justify-center">
                <div class="inline-flex items-center gap-1 rounded-xl border bg-card p-1 shadow-sm">
                    <button
                        class="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition-colors"
                        :class="tab === 'livreurs'
                            ? 'bg-primary/10 text-primary'
                            : 'text-muted-foreground hover:bg-muted hover:text-foreground'"
                        @click="setTab('livreurs')"
                    >
                        Livreurs
                        <span class="rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium tabular-nums">Hebdo</span>
                    </button>
                    <button
                        class="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition-colors"
                        :class="tab === 'proprietaires'
                            ? 'bg-primary/10 text-primary'
                            : 'text-muted-foreground hover:bg-muted hover:text-foreground'"
                        @click="setTab('proprietaires')"
                    >
                        Propriétaires
                        <span class="rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium tabular-nums">Mensuel</span>
                    </button>
                </div>
            </div>

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="partsFiltrees"
                    :paginator="partsFiltrees.length > 20"
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
                                <InputText
                                    v-model="search"
                                    :placeholder="tab === 'livreurs' ? 'Commande, livreur, équipe, véhicule…' : 'Commande, propriétaire, véhicule…'"
                                    class="w-full text-sm"
                                />
                            </IconField>
                            <Select
                                v-model="filtreStatut"
                                :options="filtresStatut"
                                option-label="label"
                                option-value="value"
                                class="w-40"
                            />
                            <Select
                                :model-value="periode"
                                :options="periodes"
                                option-label="label"
                                option-value="value"
                                class="w-44"
                                @update:model-value="setPeriode($event)"
                            />
                            <span class="text-xs text-muted-foreground">
                                {{ partsFiltrees.length }} résultat{{ partsFiltrees.length !== 1 ? 's' : '' }}
                            </span>
                        </div>
                    </template>

                    <Column field="commande_reference" header="Commande" sortable style="min-width: 190px">
                        <template #body="{ data }">
                            <Link
                                v-if="data.commande_id"
                                :href="`/ventes/${data.commande_id}`"
                                class="font-mono text-xs font-semibold text-primary hover:underline"
                            >
                                {{ data.commande_reference ?? '—' }}
                            </Link>
                            <span v-else class="font-mono text-xs">{{ data.commande_reference ?? '—' }}</span>
                            <p class="text-xs text-muted-foreground">{{ data.created_at }}</p>
                        </template>
                    </Column>

                    <Column field="vehicule_nom" header="Véhicule" sortable style="min-width: 180px">
                        <template #body="{ data }">
                            <p class="font-medium">{{ data.vehicule_nom ?? '—' }}</p>
                            <p v-if="data.immatriculation" class="font-mono text-xs text-muted-foreground">{{ data.immatriculation }}</p>
                        </template>
                    </Column>

                    <Column :field="'beneficiaire_nom'" :header="tab === 'livreurs' ? 'Livreur' : 'Propriétaire'" sortable style="min-width: 190px">
                        <template #body="{ data }">
                            <p class="font-medium">{{ data.beneficiaire_nom }}</p>
                            <p v-if="tab === 'livreurs' && data.livreur_principal_telephone" class="mt-0.5 text-xs text-muted-foreground">
                                {{ formatPhoneDisplay(data.livreur_principal_telephone) }}
                            </p>
                        </template>
                    </Column>

                    <Column field="montant_net" header="Commission" sortable style="width: 160px">
                        <template #body="{ data }">
                            <span class="font-semibold tabular-nums">{{ formatGNF(data.montant_net) }}</span>
                            <p
                                v-if="tab === 'proprietaires' && data.frais_supplementaires > 0"
                                class="mt-0.5 text-xs text-destructive tabular-nums"
                            >
                                − {{ formatGNF(data.frais_supplementaires) }} frais
                            </p>
                        </template>
                    </Column>

                    <Column field="montant_verse" header="Versé" sortable style="width: 140px">
                        <template #body="{ data }">
                            <span class="tabular-nums text-muted-foreground">{{ formatGNF(data.montant_verse) }}</span>
                        </template>
                    </Column>

                    <Column field="montant_restant" header="Restant" sortable style="width: 140px">
                        <template #body="{ data }">
                            <span :class="data.montant_restant > 0 ? 'font-semibold tabular-nums text-foreground' : 'tabular-nums text-muted-foreground'">
                                {{ formatGNF(data.montant_restant) }}
                            </span>
                        </template>
                    </Column>

                    <Column field="statut_label" header="Statut" sortable style="width: 130px">
                        <template #body="{ data }">
                            <StatusDot
                                :label="data.statut_label"
                                :dot-class="statutDotColor[data.statut] ?? 'bg-zinc-400 dark:bg-zinc-500'"
                                class="text-muted-foreground"
                            />
                        </template>
                    </Column>

                    <Column header="" style="width: 110px">
                        <template #body="{ data }">
                            <div class="flex justify-end">
                                <Link :href="`/commissions/${data.commission_id}`">
                                    <Button size="sm" variant="ghost" class="h-8 gap-1.5 text-xs">
                                        Détails
                                        <ChevronRight class="h-3.5 w-3.5" />
                                    </Button>
                                </Link>
                            </div>
                        </template>
                    </Column>

                    <template #empty>
                        <div class="py-16 text-center text-sm text-muted-foreground">
                            Aucune commission trouvée pour cette période.
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
