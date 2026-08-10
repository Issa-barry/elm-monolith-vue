<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
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
    Truck,
} from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

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

interface Fournisseur {
    id: string;
    reference: string;
    nom_complet: string | null;
    email: string | null;
    phone: string | null;
    code_phone_pays: string | null;
    ville: string | null;
    is_active: boolean;
}

const props = defineProps<{ fournisseurs: Fournisseur[] }>();

const { can } = usePermissions();
const confirm = useConfirm();
const toast = useToast();

const search = ref('');
const statusFilter = ref<string>('tous');

const totalFournisseurs = computed(() => props.fournisseurs.length);
const activeFournisseurs = computed(
    () => props.fournisseurs.filter((f) => f.is_active).length,
);
const inactiveFournisseurs = computed(
    () => props.fournisseurs.filter((f) => !f.is_active).length,
);

function applyFilters(list: Fournisseur[]): Fournisseur[] {
    const byStatus =
        statusFilter.value === 'tous'
            ? list
            : list.filter(
                  (f) => f.is_active === (statusFilter.value === 'actif'),
              );
    const q = search.value.trim().toLowerCase();
    if (!q) return byStatus;
    return byStatus.filter(
        (f) =>
            (f.nom_complet ?? '').toLowerCase().includes(q) ||
            f.reference.toLowerCase().includes(q) ||
            (f.email ?? '').toLowerCase().includes(q) ||
            (f.ville ?? '').toLowerCase().includes(q),
    );
}

const filteredFournisseurs = computed(() => applyFilters(props.fournisseurs));
const mobileFiltered = computed(() => applyFilters(props.fournisseurs));

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Fournisseurs', href: '/backoffice/fournisseurs' },
];

function confirmDelete(f: Fournisseur) {
    confirm.require({
        message: `Supprimer « ${f.nom_complet ?? f.reference} » ? Cette action est irréversible.`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(`/backoffice/fournisseurs/${f.id}`, {
                onSuccess: () =>
                    toast.add({
                        severity: 'success',
                        summary: 'Supprimé',
                        detail: `${f.nom_complet ?? f.reference} a été supprimé.`,
                        life: 3000,
                    }),
            });
        },
    });
}
</script>

<template>
    <Head title="Fournisseurs" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- ── Mobile (< sm) ──────────────────────────────────────────────── -->
        <div class="flex flex-col sm:hidden">
            <!-- Sticky header -->
            <div
                class="sticky top-0 z-10 flex items-center gap-2 border-b bg-background px-3 py-2"
            >
                <Link href="/backoffice/dashboard">
                    <Button
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8 shrink-0"
                    >
                        <ArrowLeft class="h-4 w-4" />
                    </Button>
                </Link>
                <span class="flex-1 text-center text-sm font-semibold"
                    >Fournisseurs</span
                >
                <Link
                    v-if="can('fournisseurs.create')"
                    href="/backoffice/fournisseurs/create"
                >
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
                    v-for="f in mobileFiltered"
                    :key="f.id"
                    class="flex items-center gap-3.5 px-4 py-3.5 transition-colors active:bg-muted/40"
                >
                    <!-- Avatar -->
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground"
                    >
                        {{ initials(f.nom_complet) }}
                    </div>

                    <!-- Info -->
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium">
                            {{ f.nom_complet ?? '—' }}
                        </div>
                        <div
                            v-if="f.phone"
                            class="truncate text-xs text-muted-foreground"
                        >
                            {{ formatPhoneDisplay(f.phone, f.code_phone_pays) }}
                        </div>
                    </div>

                    <!-- Status dot -->
                    <StatusDot
                        :label="f.is_active ? 'Actif' : 'Inactif'"
                        :dot-class="
                            f.is_active
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
                                v-if="can('fournisseurs.update')"
                                as-child
                            >
                                <Link
                                    :href="`/backoffice/fournisseurs/${f.id}/edit`"
                                    class="flex w-full items-center gap-2"
                                >
                                    <Pencil class="h-4 w-4" />
                                    Modifier
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator
                                v-if="
                                    can('fournisseurs.update') &&
                                    can('fournisseurs.delete')
                                "
                            />
                            <DropdownMenuItem
                                v-if="can('fournisseurs.delete')"
                                class="cursor-pointer text-destructive focus:text-destructive"
                                @click="confirmDelete(f)"
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
                <Truck class="h-12 w-12 opacity-30" />
                <p class="text-sm">Aucun fournisseur trouvé.</p>
                <Link
                    v-if="can('fournisseurs.create')"
                    href="/backoffice/fournisseurs/create"
                >
                    <Button variant="outline" size="sm">
                        <Plus class="mr-2 h-4 w-4" />
                        Ajouter le premier fournisseur
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
                        Fournisseurs
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ filteredFournisseurs.length }} fournisseur{{
                            filteredFournisseurs.length !== 1 ? 's' : ''
                        }}
                    </p>
                </div>
                <Link
                    v-if="can('fournisseurs.create')"
                    href="/backoffice/fournisseurs/create"
                >
                    <Button>
                        <Plus class="mr-2 h-4 w-4" />
                        Nouveau fournisseur
                    </Button>
                </Link>
            </div>

            <!-- Stats -->
            <div v-if="showEntityStatsCards" class="grid grid-cols-3 gap-4">
                <div class="rounded-xl border bg-card p-5">
                    <p class="text-sm text-muted-foreground">
                        Total fournisseurs
                    </p>
                    <p class="mt-1 text-3xl font-bold">
                        {{ totalFournisseurs }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5">
                    <p class="text-sm text-muted-foreground">
                        Fournisseurs actifs
                    </p>
                    <p class="mt-1 text-3xl font-bold text-emerald-500">
                        {{ activeFournisseurs }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5">
                    <p class="text-sm text-muted-foreground">
                        Fournisseurs inactifs
                    </p>
                    <p class="mt-1 text-3xl font-bold text-zinc-400">
                        {{ inactiveFournisseurs }}
                    </p>
                </div>
            </div>

            <!-- Filtres -->
            <DataFilters
                :values="{ nom: search, statut: statusFilter }"
                :fields="
                    [
                        {
                            key: 'nom',
                            type: 'text',
                            label: 'Rechercher',
                            inline: true,
                            placeholder: 'Rechercher...',
                        },
                        {
                            key: 'statut',
                            type: 'select',
                            label: 'Statut',
                            options: [
                                { value: 'tous', label: 'Tous' },
                                { value: 'actif', label: 'Actif' },
                                { value: 'inactif', label: 'Inactif' },
                            ],
                        },
                    ] as FilterField[]
                "
                :result-count="filteredFournisseurs.length"
                :hide-agence-selector="true"
                @apply="
                    (vals) => {
                        search = (vals.nom as string) || '';
                        statusFilter = (vals.statut as string) || 'tous';
                    }
                "
                @reset="
                    () => {
                        search = '';
                        statusFilter = 'tous';
                    }
                "
            />

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="filteredFournisseurs"
                    :paginator="totalFournisseurs > 20"
                    :rows="20"
                    data-key="id"
                    striped-rows
                    removable-sort
                    class="text-sm"
                    table-class="w-full"
                    :pt="{
                        root: { class: 'w-full' },
                        tbody: { class: 'divide-y' },
                    }"
                >
                    <!-- Référence -->
                    <Column
                        field="reference"
                        header="Réf."
                        sortable
                        style="width: 120px"
                    >
                        <template #body="{ data }">
                            <span
                                class="font-mono text-xs font-semibold whitespace-nowrap text-muted-foreground"
                                >{{ data.reference }}</span
                            >
                        </template>
                    </Column>

                    <!-- Nom -->
                    <Column
                        field="nom_complet"
                        header="Fournisseur"
                        sortable
                        style="min-width: 300px"
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
                                        {{ data.nom_complet ?? '—' }}
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
                        field="phone"
                        header="Téléphone"
                        style="width: 190px"
                    >
                        <template #body="{ data }">
                            <span
                                class="whitespace-nowrap text-muted-foreground tabular-nums"
                                >{{
                                    formatPhoneDisplay(
                                        data.phone,
                                        data.code_phone_pays,
                                    )
                                }}</span
                            >
                        </template>
                    </Column>

                    <!-- Ville -->
                    <Column
                        field="ville"
                        header="Ville"
                        sortable
                        style="width: 170px"
                    >
                        <template #body="{ data }">
                            <span class="text-muted-foreground">{{
                                data.ville ?? '—'
                            }}</span>
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
                                            v-if="can('fournisseurs.update')"
                                            as-child
                                        >
                                            <Link
                                                :href="`/backoffice/fournisseurs/${data.id}/edit`"
                                                class="flex w-full items-center gap-2"
                                            >
                                                <Pencil class="h-4 w-4" />
                                                Modifier
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator
                                            v-if="
                                                can('fournisseurs.update') &&
                                                can('fournisseurs.delete')
                                            "
                                        />
                                        <DropdownMenuItem
                                            v-if="can('fournisseurs.delete')"
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
                            <Truck class="h-12 w-12 opacity-30" />
                            <p class="text-sm">Aucun fournisseur trouvé.</p>
                            <Link
                                v-if="can('fournisseurs.create')"
                                href="/backoffice/fournisseurs/create"
                            >
                                <Button variant="outline" size="sm">
                                    <Plus class="mr-2 h-4 w-4" />
                                    Ajouter le premier fournisseur
                                </Button>
                            </Link>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
