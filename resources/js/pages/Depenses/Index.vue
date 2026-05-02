<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Check, Pencil, Plus, Trash2, X } from 'lucide-vue-next';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface DepenseType { id: string; code: string; libelle: string; }
interface Depense {
    id: string;
    montant: number;
    date_depense: string;
    statut: string;
    commentaire: string | null;
    type: { id: string; libelle: string; code: string };
    vehicule: { id: string; nom: string } | null;
    site: { id: string; nom: string } | null;
    user: { id: string; name: string };
}

interface Paginator {
    data: Depense[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
}

const props = defineProps<{
    depenses: Paginator;
    types: DepenseType[];
    filters: { type?: string; statut?: string; date_debut?: string; date_fin?: string };
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dépenses', href: '/depenses' },
];

const filterType   = ref(props.filters.type ?? '');
const filterStatut = ref(props.filters.statut ?? '');
const filterDebut  = ref(props.filters.date_debut ?? '');
const filterFin    = ref(props.filters.date_fin ?? '');

function applyFilters() {
    router.get('/depenses', {
        type: filterType.value || undefined,
        statut: filterStatut.value || undefined,
        date_debut: filterDebut.value || undefined,
        date_fin: filterFin.value || undefined,
    }, { preserveScroll: true, replace: true });
}

function resetFilters() {
    filterType.value = '';
    filterStatut.value = '';
    filterDebut.value = '';
    filterFin.value = '';
    router.get('/depenses', {}, { preserveScroll: true, replace: true });
}

function approuver(id: string) {
    router.patch(`/depenses/${id}/approuver`, {}, { preserveScroll: true });
}

function rejeter(id: string) {
    router.patch(`/depenses/${id}/rejeter`, {}, { preserveScroll: true });
}

function destroy(id: string) {
    if (!confirm('Supprimer cette dépense ?')) return;
    router.delete(`/depenses/${id}`, { preserveScroll: true });
}

const statutVariant: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    brouillon: 'secondary',
    soumis:    'outline',
    approuve:  'default',
    rejete:    'destructive',
};
const statutLabel: Record<string, string> = {
    brouillon: 'Brouillon',
    soumis:    'Soumis',
    approuve:  'Approuvé',
    rejete:    'Rejeté',
};

function fmt(n: number) {
    return n.toLocaleString('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + ' GNF';
}
</script>

<template>
    <Head title="Dépenses" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-5 p-4 sm:p-6">
            <!-- Header -->
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold">Dépenses opérationnelles</h1>
                    <p class="text-sm text-muted-foreground">{{ depenses.total }} dépense{{ depenses.total > 1 ? 's' : '' }}</p>
                </div>
                <Button as-child size="sm">
                    <Link href="/depenses/create">
                        <Plus class="mr-1.5 h-3.5 w-3.5" />
                        Nouvelle dépense
                    </Link>
                </Button>
            </div>

            <!-- Filtres -->
            <div class="flex flex-wrap gap-2 rounded-lg border bg-muted/20 p-3">
                <select v-model="filterType" class="h-8 rounded-md border border-input bg-background px-2 text-sm">
                    <option value="">Tous les types</option>
                    <option v-for="t in types" :key="t.id" :value="t.id">{{ t.libelle }}</option>
                </select>
                <select v-model="filterStatut" class="h-8 rounded-md border border-input bg-background px-2 text-sm">
                    <option value="">Tous les statuts</option>
                    <option value="brouillon">Brouillon</option>
                    <option value="soumis">Soumis</option>
                    <option value="approuve">Approuvé</option>
                    <option value="rejete">Rejeté</option>
                </select>
                <input v-model="filterDebut" type="date" class="h-8 rounded-md border border-input bg-background px-2 text-sm" />
                <input v-model="filterFin"   type="date" class="h-8 rounded-md border border-input bg-background px-2 text-sm" />
                <Button size="sm" @click="applyFilters">Filtrer</Button>
                <Button size="sm" variant="ghost" @click="resetFilters">Réinitialiser</Button>
            </div>

            <!-- Table -->
            <div class="rounded-lg border">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40">
                            <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Date</th>
                            <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Type</th>
                            <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Site / Véhicule</th>
                            <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Saisi par</th>
                            <th class="px-4 py-2.5 text-right font-medium text-muted-foreground">Montant</th>
                            <th class="px-4 py-2.5 text-center font-medium text-muted-foreground">Statut</th>
                            <th class="px-4 py-2.5 text-right font-medium text-muted-foreground">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="d in depenses.data"
                            :key="d.id"
                            class="border-b last:border-b-0 transition-colors hover:bg-muted/30"
                        >
                            <td class="px-4 py-3 text-sm text-muted-foreground whitespace-nowrap">{{ d.date_depense }}</td>
                            <td class="px-4 py-3">
                                <div class="font-medium">{{ d.type.libelle }}</div>
                                <div v-if="d.commentaire" class="mt-0.5 truncate text-xs text-muted-foreground max-w-[180px]">
                                    {{ d.commentaire }}
                                </div>
                            </td>
                            <td class="px-4 py-3 text-xs text-muted-foreground">
                                <div v-if="d.site">{{ d.site.nom }}</div>
                                <div v-if="d.vehicule">{{ d.vehicule.nom }}</div>
                                <span v-if="!d.site && !d.vehicule">—</span>
                            </td>
                            <td class="px-4 py-3 text-xs text-muted-foreground">{{ d.user.name }}</td>
                            <td class="px-4 py-3 text-right font-mono font-medium whitespace-nowrap">{{ fmt(d.montant) }}</td>
                            <td class="px-4 py-3 text-center">
                                <Badge :variant="statutVariant[d.statut] ?? 'secondary'">
                                    {{ statutLabel[d.statut] ?? d.statut }}
                                </Badge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-0.5">
                                    <!-- Approuver / Rejeter (uniquement si soumis + permission update) -->
                                    <template v-if="d.statut === 'soumis' && can('depenses.update')">
                                        <button
                                            type="button"
                                            title="Approuver"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-green-100 hover:text-green-700"
                                            @click="approuver(d.id)"
                                        >
                                            <Check class="h-3.5 w-3.5" />
                                        </button>
                                        <button
                                            type="button"
                                            title="Rejeter"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                                            @click="rejeter(d.id)"
                                        >
                                            <X class="h-3.5 w-3.5" />
                                        </button>
                                    </template>

                                    <!-- Modifier (brouillon ou rejeté seulement) -->
                                    <Link
                                        v-if="['brouillon', 'rejete'].includes(d.statut)"
                                        :href="`/depenses/${d.id}/edit`"
                                        class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                        title="Modifier"
                                    >
                                        <Pencil class="h-3.5 w-3.5" />
                                    </Link>

                                    <!-- Supprimer (brouillon seulement) -->
                                    <button
                                        v-if="d.statut === 'brouillon'"
                                        type="button"
                                        title="Supprimer"
                                        class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                                        @click="destroy(d.id)"
                                    >
                                        <Trash2 class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="depenses.data.length === 0">
                            <td colspan="7" class="px-4 py-12 text-center text-sm text-muted-foreground">
                                Aucune dépense enregistrée.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="depenses.last_page > 1" class="flex justify-center gap-1">
                <template v-for="link in depenses.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        class="inline-flex h-8 min-w-[2rem] items-center justify-center rounded-md border px-2 text-sm transition-colors hover:bg-muted"
                        :class="{ 'border-primary bg-primary text-primary-foreground hover:bg-primary/90': link.active }"
                        v-html="link.label"
                    />
                    <span
                        v-else
                        class="inline-flex h-8 min-w-[2rem] items-center justify-center rounded-md border px-2 text-sm opacity-50"
                        v-html="link.label"
                    />
                </template>
            </div>
        </div>
    </AppLayout>
</template>
