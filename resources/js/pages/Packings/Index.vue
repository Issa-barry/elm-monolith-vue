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
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Eye,
    Layers,
    MoreVertical,
    Pencil,
    Plus,
    Search,
    Trash2,
    XCircle,
} from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, ref, watch } from 'vue';

// ── Props ─────────────────────────────────────────────────────────────────────
interface Packing {
    id: number;
    reference: string;
    prestataire_id: number;
    prestataire_nom: string | null;
    date: string;
    shift: string;
    shift_label: string;
    nb_rouleaux: number;
    prix_par_rouleau: number;
    montant: number;
    montant_verse: number;
    montant_restant: number;
    statut: string;
    statut_label: string;
    notes: string | null;
    can_edit: boolean;
    can_cancel: boolean;
}

const props = defineProps<{ packings: Packing[] }>();

const { can } = usePermissions();
const confirm = useConfirm();
const toast = useToast();

const search = ref('');
const filters = ref({ global: { value: '', matchMode: 'contains' } });
watch(search, (val) => {
    filters.value.global.value = val;
});

const mobileFiltered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.packings;
    return props.packings.filter(
        (p) =>
            p.reference.toLowerCase().includes(q) ||
            (p.prestataire_nom ?? '').toLowerCase().includes(q) ||
            p.statut_label.toLowerCase().includes(q),
    );
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Packings', href: '/packings' },
];

// ── Badges shift ──────────────────────────────────────────────────────────────
const shiftIcon: Record<string, string> = {
    jour: '☀',
    nuit: '🌙',
};

// ── Badges statut ─────────────────────────────────────────────────────────────
const statutColor: Record<string, string> = {
    impayee: 'bg-amber-500',
    partielle: 'bg-blue-500',
    payee: 'bg-emerald-500',
    annulee: 'bg-zinc-400 dark:bg-zinc-500',
};

// ── Formatage ─────────────────────────────────────────────────────────────────
function formatGNF(val: number): string {
    return (
        new Intl.NumberFormat('fr-FR', {
            style: 'decimal',
            maximumFractionDigits: 0,
        }).format(val) + ' GNF'
    );
}

function formatDate(val: string): string {
    if (!val) return '—';
    return new Date(val).toLocaleDateString('fr-FR');
}

// ── Annulation ────────────────────────────────────────────────────────────────
function confirmAnnuler(packing: Packing) {
    confirm.require({
        message: `Annuler le packing « ${packing.reference} » ? Cette action ne peut pas être défaite.`,
        header: "Confirmer l'annulation",
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Retour',
        acceptLabel: 'Annuler le packing',
        acceptClass: 'p-button-warning',
        accept: () => {
            router.patch(
                `/packings/${packing.id}/annuler`,
                {},
                {
                    onSuccess: () =>
                        toast.add({
                            severity: 'success',
                            summary: 'Annulé',
                            detail: `${packing.reference} a été annulé.`,
                            life: 3000,
                        }),
                },
            );
        },
    });
}

// ── Suppression ───────────────────────────────────────────────────────────────
function confirmDelete(packing: Packing) {
    confirm.require({
        message: `Supprimer le packing « ${packing.reference} » ? Cette action est irréversible.`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(`/packings/${packing.id}`, {
                onSuccess: () =>
                    toast.add({
                        severity: 'success',
                        summary: 'Supprimé',
                        detail: `${packing.reference} a été supprimé.`,
                        life: 3000,
                    }),
            });
        },
    });
}
</script>

<template>
    <Head title="Packings" />

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
                    >Packings</span
                >
                <Link v-if="can('packings.create')" href="/packings/create">
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
                        placeholder="Rechercher un packing..."
                        class="w-full rounded-lg border bg-background py-2 pr-3 pl-9 text-sm outline-none focus:ring-2 focus:ring-ring"
                    />
                </div>
            </div>

            <!-- Card list -->
            <div class="divide-y">
                <div
                    v-for="p in mobileFiltered"
                    :key="p.id"
                    class="flex items-center gap-3.5 px-4 py-3.5 transition-colors active:bg-muted/40"
                >
                    <!-- Icon -->
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border bg-muted/30"
                    >
                        <Layers class="h-5 w-5 text-muted-foreground" />
                    </div>

                    <!-- Info -->
                    <div class="min-w-0 flex-1">
                        <Link
                            :href="`/packings/${p.id}`"
                            class="font-mono text-xs font-semibold tracking-wide text-foreground hover:underline"
                        >
                            {{ p.reference }}
                        </Link>
                        <div class="truncate text-xs text-muted-foreground">
                            {{ p.prestataire_nom ?? '—' }}
                        </div>
                        <div class="mt-0.5 flex items-center gap-2">
                            <span
                                class="text-[11px] text-muted-foreground tabular-nums"
                                >{{ formatDate(p.date) }}</span
                            >
                            <span class="text-[11px] text-muted-foreground">{{
                                shiftIcon[p.shift] ?? p.shift_label
                            }}</span>
                            <span
                                class="text-[11px] font-medium tabular-nums"
                                >{{ formatGNF(p.montant) }}</span
                            >
                        </div>
                    </div>

                    <!-- Status dot -->
                    <StatusDot
                        :label="p.statut_label"
                        :dot-class="
                            statutColor[p.statut] ??
                            'bg-zinc-400 dark:bg-zinc-500'
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
                                v-if="can('packings.read')"
                                as-child
                            >
                                <Link
                                    :href="`/packings/${p.id}`"
                                    class="flex w-full items-center gap-2"
                                >
                                    <Eye class="h-4 w-4" />
                                    Voir
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="p.can_edit && can('packings.update')"
                                as-child
                            >
                                <Link
                                    :href="`/packings/${p.id}/edit`"
                                    class="flex w-full items-center gap-2"
                                >
                                    <Pencil class="h-4 w-4" />
                                    Modifier
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator
                                v-if="
                                    (p.can_cancel || p.can_edit) &&
                                    can('packings.update')
                                "
                            />
                            <DropdownMenuItem
                                v-if="p.can_cancel && can('packings.update')"
                                class="cursor-pointer text-amber-600 focus:text-amber-600"
                                @click="confirmAnnuler(p)"
                            >
                                <XCircle class="h-4 w-4" />
                                Annuler
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="p.can_edit && can('packings.delete')"
                                class="cursor-pointer text-destructive focus:text-destructive"
                                @click="confirmDelete(p)"
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
                <Layers class="h-12 w-12 opacity-30" />
                <p class="text-sm">Aucun packing trouvé.</p>
                <Link v-if="can('packings.create')" href="/packings/create">
                    <Button variant="outline" size="sm">
                        <Plus class="mr-2 h-4 w-4" />
                        Créer le premier packing
                    </Button>
                </Link>
            </div>
        </div>

        <!-- ── Desktop (≥ sm) ─────────────────────────────────────────────── -->
        <div class="hidden flex-col gap-6 p-6 sm:flex">
            <!-- En-tête ──────────────────────────────────────────────────────── -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Packings
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ packings.length }} packing{{
                            packings.length !== 1 ? 's' : ''
                        }}
                        enregistré{{ packings.length !== 1 ? 's' : '' }}
                    </p>
                </div>

                <Link v-if="can('packings.create')" href="/packings/create">
                    <Button>
                        <Plus class="mr-2 h-4 w-4" />
                        Nouveau packing
                    </Button>
                </Link>
            </div>

            <!-- Tableau ──────────────────────────────────────────────────────── -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="packings"
                    :paginator="packings.length > 20"
                    :rows="20"
                    :global-filter-fields="[
                        'reference',
                        'prestataire_nom',
                        'statut_label',
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
                    <!-- Barre de recherche -->
                    <template #header>
                        <div class="flex items-center gap-3">
                            <IconField class="max-w-sm flex-1">
                                <InputIcon class="pointer-events-none">
                                    <Search
                                        class="h-4 w-4 text-muted-foreground"
                                    />
                                </InputIcon>
                                <InputText
                                    v-model="search"
                                    placeholder="Rechercher un packing..."
                                    class="w-full text-sm"
                                />
                            </IconField>
                            <span class="text-xs text-muted-foreground">
                                {{ packings.length }} résultat{{
                                    packings.length !== 1 ? 's' : ''
                                }}
                            </span>
                        </div>
                    </template>

                    <!-- Référence -->
                    <Column
                        field="reference"
                        header="Référence"
                        sortable
                        style="width: 200px"
                    >
                        <template #body="{ data }">
                            <Link
                                :href="`/packings/${data.id}`"
                                class="font-mono text-xs font-semibold tracking-wide whitespace-nowrap text-foreground hover:underline"
                            >
                                {{ data.reference }}
                            </Link>
                        </template>
                    </Column>

                    <!-- Prestataire -->
                    <Column
                        field="prestataire_nom"
                        header="Prestataire"
                        sortable
                        style="min-width: 240px"
                    >
                        <template #body="{ data }">
                            <span class="font-medium">{{
                                data.prestataire_nom ?? '—'
                            }}</span>
                        </template>
                    </Column>

                    <!-- Date -->
                    <Column
                        field="date"
                        header="Date"
                        sortable
                        style="width: 130px"
                    >
                        <template #body="{ data }">
                            <span
                                class="whitespace-nowrap text-muted-foreground tabular-nums"
                                >{{ formatDate(data.date) }}</span
                            >
                        </template>
                    </Column>

                    <!-- Shift -->
                    <Column
                        field="shift"
                        header="Shift"
                        sortable
                        style="width: 90px"
                    >
                        <template #body="{ data }">
                            <span class="whitespace-nowrap text-sm">
                                {{ shiftIcon[data.shift] ?? '' }}
                                {{ data.shift_label }}
                            </span>
                        </template>
                    </Column>

                    <!-- Nb rouleaux -->
                    <Column
                        field="nb_rouleaux"
                        header="Rouleaux"
                        sortable
                        style="width: 110px"
                    >
                        <template #body="{ data }">
                            <span class="whitespace-nowrap tabular-nums">{{
                                data.nb_rouleaux.toLocaleString('fr-FR')
                            }}</span>
                        </template>
                    </Column>

                    <!-- Montant total -->
                    <Column
                        field="montant"
                        header="Montant"
                        sortable
                        style="width: 170px"
                    >
                        <template #body="{ data }">
                            <span
                                class="font-medium whitespace-nowrap tabular-nums"
                                >{{ formatGNF(data.montant) }}</span
                            >
                        </template>
                    </Column>

                    <!-- Versé -->
                    <Column
                        field="montant_verse"
                        header="Versé"
                        sortable
                        style="width: 170px"
                    >
                        <template #body="{ data }">
                            <span
                                class="whitespace-nowrap text-emerald-600 tabular-nums dark:text-emerald-400"
                                >{{ formatGNF(data.montant_verse) }}</span
                            >
                        </template>
                    </Column>

                    <!-- Restant -->
                    <Column
                        field="montant_restant"
                        header="Restant"
                        sortable
                        style="width: 170px"
                    >
                        <template #body="{ data }">
                            <span
                                class="whitespace-nowrap tabular-nums"
                                :class="
                                    data.montant_restant > 0
                                        ? 'text-amber-600 dark:text-amber-400'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{ formatGNF(data.montant_restant) }}
                            </span>
                        </template>
                    </Column>

                    <!-- Statut -->
                    <Column
                        field="statut"
                        header="Statut"
                        sortable
                        style="width: 130px"
                    >
                        <template #body="{ data }">
                            <StatusDot
                                :label="data.statut_label"
                                :dot-class="
                                    statutColor[data.statut] ??
                                    'bg-zinc-400 dark:bg-zinc-500'
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
                                            v-if="can('packings.read')"
                                            as-child
                                        >
                                            <Link
                                                :href="`/packings/${data.id}`"
                                                class="flex w-full items-center gap-2"
                                            >
                                                <Eye class="h-4 w-4" />
                                                Voir
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="
                                                data.can_edit &&
                                                can('packings.update')
                                            "
                                            as-child
                                        >
                                            <Link
                                                :href="`/packings/${data.id}/edit`"
                                                class="flex w-full items-center gap-2"
                                            >
                                                <Pencil class="h-4 w-4" />
                                                Modifier
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator
                                            v-if="
                                                (data.can_cancel ||
                                                    data.can_edit) &&
                                                can('packings.update')
                                            "
                                        />
                                        <DropdownMenuItem
                                            v-if="
                                                data.can_cancel &&
                                                can('packings.update')
                                            "
                                            class="cursor-pointer text-amber-600 focus:text-amber-600"
                                            @click="confirmAnnuler(data)"
                                        >
                                            <XCircle class="h-4 w-4" />
                                            Annuler
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="
                                                data.can_edit &&
                                                can('packings.delete')
                                            "
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
                            <Layers class="h-12 w-12 opacity-30" />
                            <p class="text-sm">Aucun packing trouvé.</p>
                            <Link
                                v-if="can('packings.create')"
                                href="/packings/create"
                            >
                                <Button variant="outline" size="sm">
                                    <Plus class="mr-2 h-4 w-4" />
                                    Créer le premier packing
                                </Button>
                            </Link>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
