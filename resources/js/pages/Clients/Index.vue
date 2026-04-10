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
import { showEntityStatsCards } from '@/composables/useEntityConfig';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    MoreVertical,
    Pencil,
    Plus,
    Search,
    Trash2,
    Users,
} from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, ref, watch } from 'vue';

function initials(name: string | null | undefined): string {
    if (!name) return '?';
    return name
        .trim()
        .split(/\s+/)
        .map((w) => w[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

interface Client {
    id: number;
    nom: string;
    prenom: string;
    nom_complet: string;
    email: string | null;
    telephone: string | null;
    code_phone_pays: string | null;
    ville: string | null;
    pays: string | null;
    code_pays: string | null;
    adresse: string | null;
    is_active: boolean;
}

const props = defineProps<{ clients: Client[] }>();

const { can } = usePermissions();
const confirm = useConfirm();
const toast = useToast();

const search = ref('');
const statusFilter = ref<boolean | null>(null);
const filters = ref({ global: { value: '', matchMode: 'contains' } });
watch(search, (val) => {
    filters.value.global.value = val;
});

const totalClients = computed(() => props.clients.length);
const activeClients = computed(
    () => props.clients.filter((c) => c.is_active).length,
);
const inactiveClients = computed(
    () => props.clients.filter((c) => !c.is_active).length,
);

function applyFilters(list: Client[]): Client[] {
    const byStatus =
        statusFilter.value === null
            ? list
            : list.filter((c) => c.is_active === statusFilter.value);
    const q = search.value.trim().toLowerCase();
    if (!q) return byStatus;
    return byStatus.filter(
        (c) =>
            c.nom_complet.toLowerCase().includes(q) ||
            (c.email ?? '').toLowerCase().includes(q) ||
            (c.adresse ?? '').toLowerCase().includes(q) ||
            (c.ville ?? '').toLowerCase().includes(q) ||
            (c.pays ?? '').toLowerCase().includes(q),
    );
}

const filteredClients = computed(() => applyFilters(props.clients));
const mobileFiltered = computed(() => applyFilters(props.clients));

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Clients', href: '/clients' },
];

function flagUrl(code: string) {
    return `https://flagcdn.com/20x15/${code.toLowerCase()}.png`;
}

function formatLocation(
    adresse: string | null | undefined,
    ville: string | null | undefined,
): string {
    const address = (adresse ?? '').trim();
    const city = (ville ?? '').trim();

    if (!address && !city) return '-';
    if (!address) return city;
    if (!city) return address;
    if (address.toLowerCase().includes(city.toLowerCase())) return address;

    return `${address}, ${city}`;
}

function confirmDelete(c: Client) {
    confirm.require({
        message: `Supprimer « ${c.nom_complet} » ? Cette action est irréversible.`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(`/clients/${c.id}`, {
                onSuccess: () =>
                    toast.add({
                        severity: 'success',
                        summary: 'Supprimé',
                        detail: `${c.nom_complet} a été supprimé.`,
                        life: 3000,
                    }),
            });
        },
    });
}
</script>

<template>
    <Head title="Clients" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- ── Mobile (< sm) ──────────────────────────────────────────────── -->
        <div class="flex flex-col sm:hidden">
            <!-- Sticky header -->
            <div
                class="sticky top-0 z-10 flex items-center gap-2 border-b bg-background px-3 py-2"
            >
                <Link href="/dashboard">
                    <Button
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8 shrink-0"
                    >
                        <ArrowLeft class="h-4 w-4" />
                    </Button>
                </Link>
                <span class="flex-1 text-center text-sm font-semibold"
                    >Clients</span
                >
                <Link v-if="can('clients.create')" href="/clients/create">
                    <Button size="sm" class="h-8 px-3 text-xs">
                        <Plus class="mr-1 h-3.5 w-3.5" />
                        Nouveau
                    </Button>
                </Link>
                <div v-else class="h-8 w-[72px]" />
            </div>

            <!-- Search -->
            <div class="px-3 py-2">
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Rechercher..."
                        class="w-full rounded-lg border bg-background py-2 pr-3 pl-9 text-sm outline-none focus:ring-2 focus:ring-ring"
                    />
                </div>
            </div>

            <!-- Card list -->
            <div class="divide-y">
                <div
                    v-for="c in mobileFiltered"
                    :key="c.id"
                    class="flex items-center gap-3.5 px-4 py-3.5 transition-colors active:bg-muted/40"
                >
                    <!-- Avatar -->
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground"
                    >
                        {{ initials(c.nom_complet) }}
                    </div>

                    <!-- Info -->
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium">
                            {{ c.nom_complet }}
                        </div>
                        <div
                            v-if="c.email"
                            class="truncate text-xs text-muted-foreground"
                        >
                            {{ c.email }}
                        </div>
                        <div
                            v-if="c.adresse || c.ville"
                            class="mt-0.5 flex items-center gap-1"
                        >
                            <img
                                v-if="c.code_pays"
                                :src="flagUrl(c.code_pays)"
                                class="h-3 w-auto rounded-sm"
                            />
                            <span class="text-xs text-muted-foreground">
                                {{ formatLocation(c.adresse, c.ville) }}
                            </span>
                        </div>
                    </div>

                    <!-- Status dot -->
                    <StatusDot
                        :label="c.is_active ? 'Actif' : 'Inactif'"
                        :dot-class="
                            c.is_active
                                ? 'bg-emerald-500'
                                : 'bg-zinc-400 dark:bg-zinc-500'
                        "
                        class="shrink-0 text-xs text-muted-foreground"
                    />

                    <!-- Dropdown -->
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="h-8 w-8 shrink-0"
                            >
                                <MoreVertical class="h-4 w-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-44">
                            <DropdownMenuItem
                                v-if="can('clients.update')"
                                as-child
                            >
                                <Link
                                    :href="`/clients/${c.id}/edit`"
                                    class="flex w-full items-center gap-2"
                                >
                                    <Pencil class="h-4 w-4" />
                                    Modifier
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator
                                v-if="
                                    can('clients.update') &&
                                    can('clients.delete')
                                "
                            />
                            <DropdownMenuItem
                                v-if="can('clients.delete')"
                                class="cursor-pointer text-destructive focus:text-destructive"
                                @click="confirmDelete(c)"
                            >
                                <Trash2 class="h-4 w-4" />
                                Supprimer
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            <!-- Empty state -->
            <div
                v-if="mobileFiltered.length === 0"
                class="flex flex-col items-center gap-3 py-16 text-muted-foreground"
            >
                <Users class="h-12 w-12 opacity-30" />
                <p class="text-sm">Aucun client trouvé.</p>
                <Link v-if="can('clients.create')" href="/clients/create">
                    <Button variant="outline" size="sm">
                        <Plus class="mr-2 h-4 w-4" />
                        Ajouter le premier client
                    </Button>
                </Link>
            </div>
        </div>

        <!-- ── Desktop (≥ sm) ─────────────────────────────────────────────── -->
        <div class="hidden flex-col gap-6 p-6 sm:flex">
            <!-- En-tête -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Clients
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ filteredClients.length }} client{{
                            filteredClients.length !== 1 ? 's' : ''
                        }}
                    </p>
                </div>
                <Link v-if="can('clients.create')" href="/clients/create">
                    <Button>
                        <Plus class="mr-2 h-4 w-4" />
                        Nouveau client
                    </Button>
                </Link>
            </div>

            <!-- Stats -->
            <div v-if="showEntityStatsCards" class="grid grid-cols-3 gap-4">
                <div class="rounded-xl border bg-card p-5">
                    <p class="text-sm text-muted-foreground">Total clients</p>
                    <p class="mt-1 text-3xl font-bold">{{ totalClients }}</p>
                </div>
                <div class="rounded-xl border bg-card p-5">
                    <p class="text-sm text-muted-foreground">Clients actifs</p>
                    <p class="mt-1 text-3xl font-bold text-emerald-500">
                        {{ activeClients }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5">
                    <p class="text-sm text-muted-foreground">
                        Clients inactifs
                    </p>
                    <p class="mt-1 text-3xl font-bold text-zinc-400">
                        {{ inactiveClients }}
                    </p>
                </div>
            </div>

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="filteredClients"
                    :paginator="totalClients > 20"
                    :rows="20"
                    :global-filter-fields="[
                        'nom_complet',
                        'email',
                        'telephone',
                        'adresse',
                        'ville',
                        'pays',
                    ]"
                    v-model:filters="filters"
                    data-key="id"
                    striped-rows
                    removable-sort
                    class="text-sm"
                    table-class="w-full"
                    :pt="{
                        root: { class: 'w-full' },
                        header: { class: 'border-b bg-muted/30 px-4 py-3' },
                        tbody: { class: 'divide-y' },
                    }"
                >
                    <template #header>
                        <div
                            class="flex items-center gap-3 border-b border-border bg-muted/30 px-4 py-3"
                        >
                            <IconField class="max-w-sm flex-1">
                                <InputIcon class="pointer-events-none">
                                    <Search
                                        class="h-4 w-4 text-muted-foreground"
                                    />
                                </InputIcon>
                                <InputText
                                    v-model="search"
                                    placeholder="Rechercher..."
                                    class="w-full text-sm"
                                />
                            </IconField>
                            <div class="flex gap-1">
                                <button
                                    type="button"
                                    class="rounded-md px-3 py-1.5 text-xs font-medium transition-colors"
                                    :class="
                                        statusFilter === null
                                            ? 'bg-primary text-primary-foreground'
                                            : 'bg-muted text-muted-foreground hover:bg-muted/80'
                                    "
                                    @click="statusFilter = null"
                                >
                                    Tous
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md px-3 py-1.5 text-xs font-medium transition-colors"
                                    :class="
                                        statusFilter === true
                                            ? 'bg-emerald-500 text-white'
                                            : 'bg-muted text-muted-foreground hover:bg-muted/80'
                                    "
                                    @click="statusFilter = true"
                                >
                                    Actif
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md px-3 py-1.5 text-xs font-medium transition-colors"
                                    :class="
                                        statusFilter === false
                                            ? 'bg-zinc-500 text-white'
                                            : 'bg-muted text-muted-foreground hover:bg-muted/80'
                                    "
                                    @click="statusFilter = false"
                                >
                                    Inactif
                                </button>
                            </div>
                        </div>
                    </template>

                    <!-- Nom -->
                    <Column
                        field="nom_complet"
                        header="Client"
                        sortable
                        style="min-width: 320px"
                    >
                        <template #body="{ data }">
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-semibold text-primary-foreground"
                                >
                                    {{ initials(data.nom_complet) }}
                                </div>
                                <div>
                                    <div class="font-medium">
                                        {{ data.nom_complet }}
                                    </div>
                                    <div
                                        v-if="data.email"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ data.email }}
                                    </div>
                                </div>
                            </div>
                        </template>
                    </Column>

                    <!-- Téléphone -->
                    <Column
                        field="telephone"
                        header="Téléphone"
                        style="width: 190px"
                    >
                        <template #body="{ data }">
                            <span
                                class="whitespace-nowrap text-muted-foreground tabular-nums"
                                >{{
                                    formatPhoneDisplay(
                                        data.telephone,
                                        data.code_phone_pays,
                                    )
                                }}</span
                            >
                        </template>
                    </Column>

                    <!-- Localisation -->
                    <Column
                        field="adresse"
                        header="Localisation"
                        style="min-width: 220px"
                    >
                        <template #body="{ data }">
                            <div class="flex items-center gap-2">
                                <img
                                    v-if="data.code_pays"
                                    :src="flagUrl(data.code_pays)"
                                    class="h-4 w-auto rounded-sm shadow-sm"
                                />
                                <span class="text-muted-foreground">
                                    {{
                                        formatLocation(data.adresse, data.ville)
                                    }}
                                </span>
                            </div>
                        </template>
                    </Column>

                    <!-- Statut -->
                    <Column
                        field="is_active"
                        header="Statut"
                        sortable
                        style="width: 110px"
                    >
                        <template #body="{ data }">
                            <StatusDot
                                :label="data.is_active ? 'Actif' : 'Inactif'"
                                :dot-class="
                                    data.is_active
                                        ? 'bg-emerald-500'
                                        : 'bg-zinc-400 dark:bg-zinc-500'
                                "
                                class="text-muted-foreground"
                            />
                        </template>
                    </Column>

                    <!-- Actions -->
                    <Column header="" style="width: 56px">
                        <template #body="{ data }">
                            <div class="flex justify-end">
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="h-8 w-8"
                                        >
                                            <MoreVertical class="h-4 w-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        class="w-44"
                                    >
                                        <DropdownMenuItem
                                            v-if="can('clients.update')"
                                            as-child
                                        >
                                            <Link
                                                :href="`/clients/${data.id}/edit`"
                                                class="flex w-full items-center gap-2"
                                            >
                                                <Pencil class="h-4 w-4" />
                                                Modifier
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator
                                            v-if="
                                                can('clients.update') &&
                                                can('clients.delete')
                                            "
                                        />
                                        <DropdownMenuItem
                                            v-if="can('clients.delete')"
                                            class="cursor-pointer text-destructive focus:text-destructive"
                                            @click="confirmDelete(data)"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                            Supprimer
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </template>
                    </Column>

                    <!-- État vide -->
                    <template #empty>
                        <div
                            class="flex flex-col items-center gap-3 py-16 text-muted-foreground"
                        >
                            <Users class="h-12 w-12 opacity-30" />
                            <p class="text-sm">Aucun client trouvé.</p>
                            <Link
                                v-if="can('clients.create')"
                                href="/clients/create"
                            >
                                <Button variant="outline" size="sm">
                                    <Plus class="mr-2 h-4 w-4" />
                                    Ajouter le premier client
                                </Button>
                            </Link>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
