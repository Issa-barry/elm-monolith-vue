<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
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
    Check,
    ChevronLeft,
    ChevronRight,
    Eye,
    MoreVertical,
    Pencil,
    Plus,
    Receipt,
    Search,
    Send,
    Trash2,
    X,
} from 'lucide-vue-next';
import { ref } from 'vue';

interface Option {
    value: string;
    label: string;
}

interface DepenseRow {
    id: string;
    montant: number;
    date_depense: string;
    statut: string;
    statut_label: string;
    commentaire: string | null;
    type: {
        id: string;
        libelle: string;
        categorie: string;
        categorie_label: string;
        categorie_concerne: string;
    } | null;
    beneficiaire_type: string | null;
    beneficiaire_label: string | null;
    site: { id: string; nom: string } | null;
    user: { id: string; name: string };
}

interface Paginator {
    data: DepenseRow[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
}

interface TypeOption {
    id: string;
    libelle: string;
    categorie: string;
}

const props = defineProps<{
    depenses: Paginator;
    types: TypeOption[];
    sites: { id: string; nom: string }[];
    categories: Option[];
    statuts: Option[];
    filters: {
        type?: string;
        statut?: string;
        categorie?: string;
        beneficiaire_type?: string;
        site?: string;
        date_debut?: string;
        date_fin?: string;
    };
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Dépenses', href: '/depenses' },
];

const filterType = ref(props.filters.type ?? '');
const filterStatut = ref(props.filters.statut ?? '');
const filterCategorie = ref(props.filters.categorie ?? '');
const filterSite = ref(props.filters.site ?? '');
const filterDebut = ref(props.filters.date_debut ?? '');
const filterFin = ref(props.filters.date_fin ?? '');

function applyFilters() {
    router.get(
        '/depenses',
        {
            type: filterType.value || undefined,
            statut: filterStatut.value || undefined,
            categorie: filterCategorie.value || undefined,
            site: filterSite.value || undefined,
            date_debut: filterDebut.value || undefined,
            date_fin: filterFin.value || undefined,
        },
        { preserveScroll: true, replace: true },
    );
}

function resetFilters() {
    filterType.value = '';
    filterStatut.value = '';
    filterCategorie.value = '';
    filterSite.value = '';
    filterDebut.value = '';
    filterFin.value = '';
    router.get('/depenses', {}, { preserveScroll: true, replace: true });
}

function soumettre(id: string) {
    router.patch(`/depenses/${id}/soumettre`, {}, { preserveScroll: true });
}

function valider(id: string) {
    router.patch(`/depenses/${id}/valider`, {}, { preserveScroll: true });
}

function rejeter(id: string) {
    const motif = prompt('Motif de rejet (obligatoire) :');
    if (!motif?.trim()) return;
    router.patch(`/depenses/${id}/rejeter`, { motif_rejet: motif }, { preserveScroll: true });
}

function imputer(id: string) {
    if (!confirm('Imputer cette dépense ? Cette action génère une retenue définitive.')) return;
    router.patch(`/depenses/${id}/imputer`, {}, { preserveScroll: true });
}

function destroy(id: string) {
    if (!confirm('Supprimer cette dépense en brouillon ?')) return;
    router.delete(`/depenses/${id}`, { preserveScroll: true });
}

function fmt(n: number) {
    return n.toLocaleString('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + ' GNF';
}

function formatPaginationLabel(label: string) {
    const el = document.createElement('div');
    el.innerHTML = label;
    return el.textContent?.trim() ?? label.trim();
}

const statutVariant: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    brouillon: 'secondary',
    soumis: 'outline',
    valide: 'default',
    rejete: 'destructive',
    impute: 'secondary',
};

const statutColors: Record<string, string> = {
    brouillon: '',
    soumis: 'border-blue-400 text-blue-700',
    valide: 'bg-emerald-100 text-emerald-700 border-emerald-300',
    rejete: '',
    impute: 'bg-purple-100 text-purple-700 border-purple-300',
};

const categorieColors: Record<string, string> = {
    interne: 'bg-slate-100 text-slate-600',
    employe: 'bg-blue-100 text-blue-700',
    livreur: 'bg-amber-100 text-amber-700',
    proprietaire: 'bg-purple-100 text-purple-700',
    vehicule: 'bg-green-100 text-green-700',
};

const hasActiveFilters = ref(
    !!(props.filters.type || props.filters.statut || props.filters.categorie || props.filters.site || props.filters.date_debut || props.filters.date_fin),
);
</script>

<template>
    <Head title="Dépenses" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <div class="flex flex-col gap-6 p-4 sm:p-6">
            <!-- En-tête -->
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Dépenses</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ depenses.total }} dépense{{ depenses.total !== 1 ? 's' : '' }}
                    </p>
                </div>
                <Link v-if="can('depenses.create')" href="/depenses/create">
                    <Button>
                        <Plus class="mr-2 h-4 w-4" />
                        Nouvelle dépense
                    </Button>
                </Link>
            </div>

            <!-- Filtres -->
            <div class="flex flex-wrap items-end gap-2 rounded-xl border bg-muted/30 p-3">
                <div class="flex flex-1 flex-wrap gap-2">
                    <!-- Recherche (type) -->
                    <select
                        v-model="filterType"
                        class="h-8 rounded-md border border-input bg-background px-2 text-sm"
                    >
                        <option value="">Tous les types</option>
                        <option v-for="t in types" :key="t.id" :value="t.id">{{ t.libelle }}</option>
                    </select>

                    <!-- Catégorie -->
                    <select
                        v-model="filterCategorie"
                        class="h-8 rounded-md border border-input bg-background px-2 text-sm"
                    >
                        <option value="">Toutes les catégories</option>
                        <option v-for="c in categories" :key="c.value" :value="c.value">{{ c.label }}</option>
                    </select>

                    <!-- Statut -->
                    <select
                        v-model="filterStatut"
                        class="h-8 rounded-md border border-input bg-background px-2 text-sm"
                    >
                        <option value="">Tous les statuts</option>
                        <option v-for="s in statuts" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>

                    <!-- Site -->
                    <select
                        v-model="filterSite"
                        class="h-8 rounded-md border border-input bg-background px-2 text-sm"
                    >
                        <option value="">Tous les sites</option>
                        <option v-for="s in sites" :key="s.id" :value="s.id">{{ s.nom }}</option>
                    </select>

                    <!-- Dates -->
                    <input
                        v-model="filterDebut"
                        type="date"
                        class="h-8 rounded-md border border-input bg-background px-2 text-sm"
                    />
                    <input
                        v-model="filterFin"
                        type="date"
                        class="h-8 rounded-md border border-input bg-background px-2 text-sm"
                    />
                </div>
                <div class="flex gap-2">
                    <Button size="sm" @click="applyFilters">
                        <Search class="mr-1.5 h-3.5 w-3.5" />
                        Filtrer
                    </Button>
                    <Button v-if="hasActiveFilters" size="sm" variant="ghost" @click="resetFilters">
                        <X class="mr-1.5 h-3.5 w-3.5" />
                        Réinitialiser
                    </Button>
                </div>
            </div>

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40">
                            <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Date</th>
                            <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Type / Catégorie</th>
                            <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Concerné</th>
                            <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Bénéficiaire</th>
                            <th class="px-4 py-2.5 text-right font-medium text-muted-foreground">Montant</th>
                            <th class="px-4 py-2.5 text-center font-medium text-muted-foreground">Statut</th>
                            <th class="px-4 py-2.5 text-left font-medium text-muted-foreground hidden lg:table-cell">Site</th>
                            <th class="px-4 py-2.5 text-left font-medium text-muted-foreground hidden xl:table-cell">Saisi par</th>
                            <th class="px-4 py-2.5 text-right font-medium text-muted-foreground">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="d in depenses.data"
                            :key="d.id"
                            class="border-b transition-colors last:border-b-0 hover:bg-muted/20"
                        >
                            <!-- Date -->
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-muted-foreground tabular-nums">
                                {{ d.date_depense }}
                            </td>

                            <!-- Type / Catégorie -->
                            <td class="px-4 py-3">
                                <div class="font-medium">{{ d.type?.libelle ?? '—' }}</div>
                                <span
                                    v-if="d.type"
                                    class="mt-0.5 inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="categorieColors[d.type.categorie] ?? 'bg-muted text-muted-foreground'"
                                >
                                    {{ d.type.categorie_label }}
                                </span>
                            </td>

                            <!-- Concerné -->
                            <td class="px-4 py-3">
                                <span class="text-xs text-muted-foreground">
                                    {{ d.type?.categorie_concerne ?? '—' }}
                                </span>
                            </td>

                            <!-- Bénéficiaire -->
                            <td class="px-4 py-3">
                                <span class="text-sm">{{ d.beneficiaire_label ?? '—' }}</span>
                            </td>

                            <!-- Montant -->
                            <td class="px-4 py-3 text-right font-mono font-medium whitespace-nowrap">
                                {{ fmt(d.montant) }}
                            </td>

                            <!-- Statut -->
                            <td class="px-4 py-3 text-center">
                                <Badge
                                    :variant="statutVariant[d.statut] ?? 'secondary'"
                                    :class="statutColors[d.statut]"
                                >
                                    {{ d.statut_label }}
                                </Badge>
                            </td>

                            <!-- Site -->
                            <td class="px-4 py-3 text-xs text-muted-foreground hidden lg:table-cell">
                                {{ d.site?.nom ?? '—' }}
                            </td>

                            <!-- Saisi par -->
                            <td class="px-4 py-3 text-xs text-muted-foreground hidden xl:table-cell">
                                {{ d.user.name }}
                            </td>

                            <!-- Actions -->
                            <td class="px-4 py-3">
                                <div class="flex justify-end">
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button variant="ghost" size="icon" class="h-8 w-8">
                                                <MoreVertical class="h-4 w-4" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end" class="w-48">
                                            <!-- Voir -->
                                            <DropdownMenuItem as-child>
                                                <Link :href="`/depenses/${d.id}`" class="flex w-full items-center gap-2">
                                                    <Eye class="h-4 w-4" />
                                                    Voir le détail
                                                </Link>
                                            </DropdownMenuItem>

                                            <!-- Modifier (brouillon ou rejeté) -->
                                            <DropdownMenuItem
                                                v-if="['brouillon', 'rejete'].includes(d.statut) && can('depenses.update')"
                                                as-child
                                            >
                                                <Link :href="`/depenses/${d.id}/edit`" class="flex w-full items-center gap-2">
                                                    <Pencil class="h-4 w-4" />
                                                    Modifier
                                                </Link>
                                            </DropdownMenuItem>

                                            <DropdownMenuSeparator />

                                            <!-- Soumettre (brouillon) -->
                                            <DropdownMenuItem
                                                v-if="d.statut === 'brouillon'"
                                                class="cursor-pointer"
                                                @click="soumettre(d.id)"
                                            >
                                                <Send class="h-4 w-4" />
                                                Soumettre
                                            </DropdownMenuItem>

                                            <!-- Valider (soumis + permission) -->
                                            <DropdownMenuItem
                                                v-if="d.statut === 'soumis' && can('depenses.update')"
                                                class="cursor-pointer text-emerald-700 focus:text-emerald-700"
                                                @click="valider(d.id)"
                                            >
                                                <Check class="h-4 w-4" />
                                                Valider
                                            </DropdownMenuItem>

                                            <!-- Rejeter (soumis + permission) -->
                                            <DropdownMenuItem
                                                v-if="d.statut === 'soumis' && can('depenses.update')"
                                                class="cursor-pointer text-destructive focus:text-destructive"
                                                @click="rejeter(d.id)"
                                            >
                                                <X class="h-4 w-4" />
                                                Rejeter
                                            </DropdownMenuItem>

                                            <!-- Imputer (validé + permission) -->
                                            <DropdownMenuItem
                                                v-if="d.statut === 'valide' && can('depenses.update')"
                                                class="cursor-pointer text-purple-700 focus:text-purple-700"
                                                @click="imputer(d.id)"
                                            >
                                                <Check class="h-4 w-4" />
                                                Imputer
                                            </DropdownMenuItem>

                                            <DropdownMenuSeparator v-if="d.statut === 'brouillon' && can('depenses.delete')" />

                                            <!-- Supprimer (brouillon seulement) -->
                                            <DropdownMenuItem
                                                v-if="d.statut === 'brouillon' && can('depenses.delete')"
                                                class="cursor-pointer text-destructive focus:text-destructive"
                                                @click="destroy(d.id)"
                                            >
                                                <Trash2 class="h-4 w-4" />
                                                Supprimer
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            </td>
                        </tr>

                        <tr v-if="depenses.data.length === 0">
                            <td colspan="9" class="px-4 py-16 text-center text-sm text-muted-foreground">
                                <div class="flex flex-col items-center gap-3">
                                    <Receipt class="h-12 w-12 opacity-20" />
                                    <p>Aucune dépense enregistrée.</p>
                                    <Link v-if="can('depenses.create')" href="/depenses/create">
                                        <Button variant="outline" size="sm">
                                            <Plus class="mr-2 h-4 w-4" />
                                            Enregistrer une dépense
                                        </Button>
                                    </Link>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="depenses.last_page > 1" class="flex items-center justify-center gap-1">
                <template v-for="link in depenses.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        class="inline-flex h-8 min-w-[2rem] items-center justify-center rounded-md border px-2 text-sm transition-colors hover:bg-muted"
                        :class="{ 'border-primary bg-primary text-primary-foreground hover:bg-primary/90': link.active }"
                    >
                        <ChevronLeft v-if="link.label.includes('Précédent') || link.label.includes('&laquo')" class="h-4 w-4" />
                        <ChevronRight v-else-if="link.label.includes('Suivant') || link.label.includes('&raquo')" class="h-4 w-4" />
                        <span v-else>{{ formatPaginationLabel(link.label) }}</span>
                    </Link>
                    <span
                        v-else
                        class="inline-flex h-8 min-w-[2rem] items-center justify-center rounded-md border px-2 text-sm opacity-40"
                    >
                        <ChevronLeft v-if="link.label.includes('Précédent') || link.label.includes('&laquo')" class="h-4 w-4" />
                        <ChevronRight v-else-if="link.label.includes('Suivant') || link.label.includes('&raquo')" class="h-4 w-4" />
                        <span v-else>{{ formatPaginationLabel(link.label) }}</span>
                    </span>
                </template>
            </div>
        </div>
    </AppLayout>
</template>
