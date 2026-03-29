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

interface Prestataire {
    id: number;
    reference: string;
    nom_complet: string | null;
    email: string | null;
    phone: string | null;
    code_phone_pays: string | null;
    ville: string | null;
    type: string;
    type_label: string;
    is_active: boolean;
}

const props = defineProps<{ prestataires: Prestataire[] }>();

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
    if (!q) return props.prestataires;
    return props.prestataires.filter(
        (p) =>
            (p.nom_complet ?? '').toLowerCase().includes(q) ||
            p.reference.toLowerCase().includes(q) ||
            (p.email ?? '').toLowerCase().includes(q) ||
            (p.type_label ?? '').toLowerCase().includes(q) ||
            (p.ville ?? '').toLowerCase().includes(q),
    );
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Prestataires', href: '/prestataires' },
];

const typeColor: Record<string, string> = {
    machiniste: 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
    mecanicien:
        'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    consultant:
        'bg-purple-100 text-purple-700 dark:bg-purple-950 dark:text-purple-300',
    fournisseur:
        'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
};

function confirmDelete(p: Prestataire) {
    confirm.require({
        message: `Supprimer « ${p.nom_complet ?? p.reference} » ? Cette action est irréversible.`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(`/prestataires/${p.id}`, {
                onSuccess: () =>
                    toast.add({
                        severity: 'success',
                        summary: 'Supprimé',
                        detail: `${p.nom_complet ?? p.reference} a été supprimé.`,
                        life: 3000,
                    }),
            });
        },
    });
}
</script>

<template>
    <Head title="Prestataires" />

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
                    >Prestataires</span
                >
                <Link
                    v-if="can('prestataires.create')"
                    href="/prestataires/create"
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
                    v-for="p in mobileFiltered"
                    :key="p.id"
                    class="flex items-center gap-3.5 px-4 py-3.5 transition-colors active:bg-muted/40"
                >
                    <!-- Avatar -->
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground"
                    >
                        {{ initials(p.nom_complet) }}
                    </div>

                    <!-- Info -->
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium">
                            {{ p.nom_complet ?? '—' }}
                        </div>
                        <div
                            v-if="p.email"
                            class="truncate text-xs text-muted-foreground"
                        >
                            {{ p.email }}
                        </div>
                        <span
                            class="mt-0.5 inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium"
                            :class="
                                typeColor[p.type] ??
                                'bg-muted text-muted-foreground'
                            "
                        >
                            {{ p.type_label }}
                        </span>
                    </div>

                    <!-- Status dot -->
                    <StatusDot
                        :label="p.is_active ? 'Actif' : 'Inactif'"
                        :dot-class="
                            p.is_active
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
                                v-if="can('prestataires.update')"
                                as-child
                            >
                                <Link
                                    :href="`/prestataires/${p.id}/edit`"
                                    class="flex w-full items-center gap-2"
                                >
                                    <Pencil class="h-4 w-4" />
                                    Modifier
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator
                                v-if="
                                    can('prestataires.update') &&
                                    can('prestataires.delete')
                                "
                            />
                            <DropdownMenuItem
                                v-if="can('prestataires.delete')"
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
                <Users class="h-12 w-12 opacity-30" />
                <p class="text-sm">Aucun prestataire trouvé.</p>
                <Link
                    v-if="can('prestataires.create')"
                    href="/prestataires/create"
                >
                    <Button variant="outline" size="sm">
                        <Plus class="mr-2 h-4 w-4" />
                        Ajouter le premier prestataire
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
                        Prestataires
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ prestataires.length }} prestataire{{
                            prestataires.length !== 1 ? 's' : ''
                        }}
                    </p>
                </div>
                <Link
                    v-if="can('prestataires.create')"
                    href="/prestataires/create"
                >
                    <Button>
                        <Plus class="mr-2 h-4 w-4" />
                        Nouveau prestataire
                    </Button>
                </Link>
            </div>

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="prestataires"
                    :paginator="prestataires.length > 20"
                    :rows="20"
                    :global-filter-fields="[
                        'nom_complet',
                        'reference',
                        'email',
                        'phone',
                        'type_label',
                        'ville',
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
                        <div class="flex items-center gap-3">
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
                            <span class="text-xs text-muted-foreground"
                                >{{ prestataires.length }} résultat{{
                                    prestataires.length !== 1 ? 's' : ''
                                }}</span
                            >
                        </div>
                    </template>

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
                        header="Prestataire"
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

                    <!-- Type -->
                    <Column
                        field="type"
                        header="Type"
                        sortable
                        style="width: 140px"
                    >
                        <template #body="{ data }">
                            <span
                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="
                                    typeColor[data.type] ??
                                    'bg-muted text-muted-foreground'
                                "
                            >
                                {{ data.type_label }}
                            </span>
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
                                            v-if="can('prestataires.update')"
                                            as-child
                                        >
                                            <Link
                                                :href="`/prestataires/${data.id}/edit`"
                                                class="flex w-full items-center gap-2"
                                            >
                                                <Pencil class="h-4 w-4" />
                                                Modifier
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator
                                            v-if="
                                                can('prestataires.update') &&
                                                can('prestataires.delete')
                                            "
                                        />
                                        <DropdownMenuItem
                                            v-if="can('prestataires.delete')"
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
                            <p class="text-sm">Aucun prestataire trouvé.</p>
                            <Link
                                v-if="can('prestataires.create')"
                                href="/prestataires/create"
                            >
                                <Button variant="outline" size="sm">
                                    <Plus class="mr-2 h-4 w-4" />
                                    Ajouter le premier prestataire
                                </Button>
                            </Link>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
