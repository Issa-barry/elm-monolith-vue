<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { BadgeCheck, Clock, Gift, Search } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import { computed, ref } from 'vue';

interface ClientRef {
    id: number;
    nom_complet: string;
}

interface Transaction {
    id: number;
    client: { id: number; nom_complet: string; telephone: string | null } | null;
    montant: number;
    statut: 'en_attente' | 'verse';
    vente_id: number | null;
    note: string | null;
    verse_le: string | null;
    created_at: string;
}

interface Filters {
    statut?: string;
    client_id?: string;
    date_debut?: string;
    date_fin?: string;
}

const props = defineProps<{
    transactions: Transaction[];
    clients: ClientRef[];
    filters: Filters;
}>();

const page = usePage();
const flashSuccess = computed(() => (page.props as any).flash?.success as string | undefined);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Cashback clients', href: '/cashback' },
];

// ── Filtres locaux ────────────────────────────────────────────────────────────
const statutOptions = [
    { label: 'Tous', value: '' },
    { label: 'En attente', value: 'en_attente' },
    { label: 'Versé', value: 'verse' },
];

const localStatut = ref(props.filters.statut ?? '');
const localClientId = ref(props.filters.client_id ?? '');
const search = ref('');

function applyFilters() {
    router.get(
        '/cashback',
        {
            statut: localStatut.value || undefined,
            client_id: localClientId.value || undefined,
        },
        { preserveState: true, replace: true },
    );
}

// ── Recherche locale ──────────────────────────────────────────────────────────
const filtered = computed(() => {
    const q = search.value.toLowerCase();
    if (!q) return props.transactions;
    return props.transactions.filter(
        (t) =>
            t.client?.nom_complet.toLowerCase().includes(q) ||
            t.client?.telephone?.includes(q),
    );
});

// ── Versement ─────────────────────────────────────────────────────────────────
const verserForm = useForm({ note: '' });
const verserDialog = ref<Transaction | null>(null);

function openVerser(t: Transaction) {
    verserDialog.value = t;
    verserForm.note = '';
}

function closeVerser() {
    verserDialog.value = null;
}

function submitVerser() {
    if (!verserDialog.value) return;
    verserForm.patch(`/cashback/${verserDialog.value.id}/verser`, {
        preserveScroll: true,
        onSuccess: () => closeVerser(),
    });
}

// ── Formatage ─────────────────────────────────────────────────────────────────
function formatMontant(v: number): string {
    return new Intl.NumberFormat('fr-GN').format(v) + ' GNF';
}

function formatDate(d: string | null): string {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
}
</script>

<template>
    <Head title="Cashback clients" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-6xl space-y-6 p-4 sm:p-6">
            <!-- En-tête -->
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <Gift class="h-6 w-6 text-primary" />
                    <div>
                        <h1 class="text-xl font-semibold">Cashback clients</h1>
                        <p class="text-sm text-muted-foreground">
                            Gestion des cashbacks à verser aux clients
                        </p>
                    </div>
                </div>
            </div>

            <!-- Flash -->
            <div
                v-if="flashSuccess"
                class="flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"
            >
                <BadgeCheck class="h-4 w-4 shrink-0" />
                {{ flashSuccess }}
            </div>

            <!-- Filtres -->
            <div class="flex flex-wrap items-center gap-3">
                <Select
                    v-model="localStatut"
                    :options="statutOptions"
                    option-label="label"
                    option-value="value"
                    placeholder="Statut"
                    class="w-40"
                    @change="applyFilters"
                />
                <Select
                    v-model="localClientId"
                    :options="[{ id: '', nom_complet: 'Tous les clients' }, ...clients]"
                    option-label="nom_complet"
                    option-value="id"
                    placeholder="Client"
                    class="w-56"
                    @change="applyFilters"
                />
                <IconField class="ml-auto">
                    <InputIcon>
                        <Search class="h-4 w-4" />
                    </InputIcon>
                    <InputText
                        v-model="search"
                        placeholder="Rechercher..."
                        class="w-56"
                    />
                </IconField>
            </div>

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="filtered"
                    :rows="25"
                    paginator
                    paginator-template="PrevPageLink PageLinks NextPageLink"
                    striped-rows
                    class="text-sm"
                >
                    <template #empty>
                        <div class="py-10 text-center text-muted-foreground">
                            Aucun cashback trouvé.
                        </div>
                    </template>

                    <Column field="client.nom_complet" header="Client" sortable>
                        <template #body="{ data }">
                            <div>
                                <p class="font-medium">
                                    {{ data.client?.nom_complet ?? '—' }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ data.client?.telephone ?? '' }}
                                </p>
                            </div>
                        </template>
                    </Column>

                    <Column field="montant" header="Montant" sortable>
                        <template #body="{ data }">
                            <span class="font-semibold text-primary">
                                {{ formatMontant(data.montant) }}
                            </span>
                        </template>
                    </Column>

                    <Column field="statut" header="Statut" sortable>
                        <template #body="{ data }">
                            <span
                                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="
                                    data.statut === 'en_attente'
                                        ? 'bg-amber-100 text-amber-800'
                                        : 'bg-green-100 text-green-800'
                                "
                            >
                                <Clock
                                    v-if="data.statut === 'en_attente'"
                                    class="h-3 w-3"
                                />
                                <BadgeCheck v-else class="h-3 w-3" />
                                {{
                                    data.statut === 'en_attente'
                                        ? 'En attente'
                                        : 'Versé'
                                }}
                            </span>
                        </template>
                    </Column>

                    <Column field="created_at" header="Déclenché le" sortable>
                        <template #body="{ data }">
                            {{ formatDate(data.created_at) }}
                        </template>
                    </Column>

                    <Column field="verse_le" header="Versé le">
                        <template #body="{ data }">
                            {{ formatDate(data.verse_le) }}
                        </template>
                    </Column>

                    <Column header="Action" style="width: 120px">
                        <template #body="{ data }">
                            <button
                                v-if="data.statut === 'en_attente'"
                                class="inline-flex items-center gap-1 rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-primary-foreground shadow-sm transition-opacity hover:opacity-90 disabled:opacity-50"
                                :disabled="verserForm.processing"
                                @click="openVerser(data)"
                            >
                                <Gift class="h-3 w-3" />
                                Verser
                            </button>
                            <span
                                v-else
                                class="text-xs text-muted-foreground"
                            >
                                {{ data.note ?? '—' }}
                            </span>
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>

        <!-- Dialog versement -->
        <Teleport to="body">
            <div
                v-if="verserDialog"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                @click.self="closeVerser"
            >
                <div
                    class="w-full max-w-md rounded-2xl bg-background p-6 shadow-xl"
                >
                    <h2 class="mb-1 text-lg font-semibold">
                        Confirmer le versement
                    </h2>
                    <p class="mb-4 text-sm text-muted-foreground">
                        Client :
                        <strong>{{
                            verserDialog.client?.nom_complet
                        }}</strong>
                        — Montant :
                        <strong class="text-primary">{{
                            formatMontant(verserDialog.montant)
                        }}</strong>
                    </p>

                    <div class="mb-4">
                        <label
                            class="mb-1 block text-sm font-medium"
                            for="note"
                        >
                            Note (facultative)
                        </label>
                        <textarea
                            id="note"
                            v-model="verserForm.note"
                            rows="3"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                            placeholder="Ex : remise en main propre le 10/04/2026"
                        />
                    </div>

                    <div class="flex justify-end gap-3">
                        <button
                            class="rounded-lg border px-4 py-2 text-sm font-medium transition-colors hover:bg-muted"
                            :disabled="verserForm.processing"
                            @click="closeVerser"
                        >
                            Annuler
                        </button>
                        <button
                            class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground shadow-sm transition-opacity hover:opacity-90 disabled:opacity-60"
                            :disabled="verserForm.processing"
                            @click="submitVerser"
                        >
                            <Gift class="h-4 w-4" />
                            {{
                                verserForm.processing
                                    ? 'Versement…'
                                    : 'Confirmer le versement'
                            }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
