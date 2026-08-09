<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { flattenCategorieTree } from '@/lib/categorieTree';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { FolderTree, Pencil, Plus, Power, Trash2 } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputText from 'primevue/inputtext';
import Textarea from 'primevue/textarea';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

interface Categorie {
    id: string;
    nom: string;
    description: string | null;
    statut: string;
    statut_label: string;
    parent_id: string | null;
    position: number;
    produits_count: number;
}

const props = defineProps<{
    categories: Categorie[];
    statuts: { value: string; label: string }[];
}>();

const toast = useToast();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Produits', href: '/backoffice/produits' },
    { title: 'Catégories', href: '/backoffice/produits/categories' },
];

// ── Filtres ──────────────────────────────────────────────────────────────────

const search = ref('');
const selectedStatut = ref('');

const filterFields = computed((): FilterField[] => [
    {
        key: 'search',
        label: 'Recherche',
        type: 'text',
        placeholder: 'Nom, description…',
        inline: true,
    },
    {
        key: 'statut',
        label: 'Statut',
        type: 'select',
        options: props.statuts,
        inline: true,
    },
]);

function handleApply(values: Record<string, unknown>) {
    search.value = (values.search as string) ?? '';
    selectedStatut.value = (values.statut as string[])?.[0] ?? '';
}

function resetFilters() {
    search.value = '';
    selectedStatut.value = '';
}

// Toutes les catégories aplaties (ordre arbre) — la recherche/le statut filtrent ensuite
// cette liste sans casser l'indentation des nœuds restants.
const arbre = computed(() =>
    flattenCategorieTree(props.categories).map((flat) => ({
        ...flat,
        source: props.categories.find((c) => c.id === flat.id)!,
    })),
);

const filtered = computed(() => {
    let data = arbre.value;
    if (selectedStatut.value) {
        data = data.filter((c) => c.source.statut === selectedStatut.value);
    }
    const q = search.value.trim().toLowerCase();
    if (q) {
        data = data.filter(
            (c) =>
                c.displayLabel.toLowerCase().includes(q) ||
                (c.source.description ?? '').toLowerCase().includes(q),
        );
    }

    return data;
});

// ── Dialog Create / Edit ──────────────────────────────────────────────────────

const showDialog = ref(false);
const editingCategorie = ref<Categorie | null>(null);

const dialogTitle = computed(() =>
    editingCategorie.value ? 'Modifier la catégorie' : 'Créer une catégorie',
);

const form = useForm({
    nom: '',
    parent_id: null as string | null,
    description: null as string | null,
    statut: 'actif',
});

// Une catégorie ne peut pas devenir son propre parent ni celui d'un de ses descendants
// (même garde-fou que UpdateCategorieRequest côté serveur — évitée ici pour ne pas
// proposer un choix qui échouera de toute façon).
const parentOptions = computed(() => {
    if (!editingCategorie.value) return flattenCategorieTree(props.categories);
    const interdits = new Set<string>([editingCategorie.value.id]);
    let changed = true;
    while (changed) {
        changed = false;
        for (const c of props.categories) {
            if (
                c.parent_id &&
                interdits.has(c.parent_id) &&
                !interdits.has(c.id)
            ) {
                interdits.add(c.id);
                changed = true;
            }
        }
    }

    return flattenCategorieTree(
        props.categories.filter((c) => !interdits.has(c.id)),
    );
});

function openCreate() {
    editingCategorie.value = null;
    form.reset();
    form.parent_id = null;
    form.statut = 'actif';
    showDialog.value = true;
}

function openEdit(categorie: Categorie) {
    editingCategorie.value = categorie;
    form.nom = categorie.nom;
    form.parent_id = categorie.parent_id;
    form.description = categorie.description;
    form.statut = categorie.statut;
    showDialog.value = true;
}

function handleSubmit() {
    if (editingCategorie.value) {
        form.put(
            `/backoffice/produits/categories/${editingCategorie.value.id}`,
            {
                preserveScroll: true,
                onSuccess: () => {
                    showDialog.value = false;
                    toast.add({
                        severity: 'success',
                        summary: 'Catégorie mise à jour',
                        life: 3000,
                    });
                },
            },
        );
    } else {
        form.post('/backoffice/produits/categories', {
            preserveScroll: true,
            onSuccess: () => {
                showDialog.value = false;
                form.reset();
                toast.add({
                    severity: 'success',
                    summary: 'Catégorie créée',
                    life: 3000,
                });
            },
        });
    }
}

// ── Actions ───────────────────────────────────────────────────────────────────

function toggle(categorie: Categorie) {
    router.patch(
        `/backoffice/produits/categories/${categorie.id}/toggle`,
        {},
        { preserveScroll: true },
    );
}

function destroy(categorie: Categorie) {
    if (categorie.produits_count > 0) {
        toast.add({
            severity: 'warn',
            summary: 'Suppression impossible',
            detail: `Cette catégorie est utilisée par ${categorie.produits_count} produit${categorie.produits_count > 1 ? 's' : ''}. Désactivez-la plutôt.`,
            life: 5000,
        });
        return;
    }
    if (props.categories.some((c) => c.parent_id === categorie.id)) {
        toast.add({
            severity: 'warn',
            summary: 'Suppression impossible',
            detail: "Cette catégorie a des sous-catégories. Déplacez-les ou supprimez-les d'abord.",
            life: 5000,
        });
        return;
    }
    if (!confirm(`Supprimer la catégorie « ${categorie.nom} » ?`)) return;
    router.delete(`/backoffice/produits/categories/${categorie.id}`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Catégories" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-5xl space-y-6 p-4 sm:p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Catégories
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Classement du catalogue produits — hiérarchie libre, sur
                        plusieurs niveaux.
                    </p>
                </div>
                <Button size="sm" @click="openCreate">
                    <Plus class="mr-1.5 h-3.5 w-3.5" />
                    Nouvelle catégorie
                </Button>
            </div>

            <!-- Filtres -->
            <DataFilters
                :fields="filterFields"
                :values="{ search, statut: selectedStatut }"
                :result-count="filtered.length"
                :hide-agence-selector="true"
                @apply="handleApply"
                @reset="resetFilters"
            />

            <!-- Liste -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <div v-if="filtered.length === 0" class="p-4">
                    <div
                        class="flex flex-col items-center gap-3 py-12 text-muted-foreground"
                    >
                        <FolderTree class="h-10 w-10 opacity-30" />
                        <p class="text-sm">
                            {{
                                categories.length === 0
                                    ? 'Aucune catégorie créée.'
                                    : 'Aucune catégorie ne correspond à ces filtres.'
                            }}
                        </p>
                        <Button
                            v-if="categories.length === 0"
                            variant="outline"
                            size="sm"
                            @click="openCreate"
                        >
                            <Plus class="mr-2 h-4 w-4" />
                            Créer la première catégorie
                        </Button>
                    </div>
                </div>

                <table v-else class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-xs text-muted-foreground">
                            <th class="px-4 py-2.5 text-left font-medium">
                                Nom
                            </th>
                            <th class="px-4 py-2.5 text-left font-medium">
                                Description
                            </th>
                            <th class="px-4 py-2.5 text-right font-medium">
                                Produits
                            </th>
                            <th class="px-4 py-2.5 text-left font-medium">
                                Statut
                            </th>
                            <th class="px-4 py-2.5 text-right font-medium">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/50">
                        <tr
                            v-for="c in filtered"
                            :key="c.id"
                            :class="{
                                'opacity-50': c.source.statut !== 'actif',
                            }"
                        >
                            <td class="px-4 py-2.5">
                                <span
                                    class="font-medium"
                                    :style="{
                                        paddingLeft: `${c.depth * 20}px`,
                                    }"
                                >
                                    <span
                                        v-if="c.depth > 0"
                                        class="mr-1 text-muted-foreground"
                                        >└</span
                                    >{{ c.displayLabel }}
                                </span>
                            </td>
                            <td
                                class="max-w-xs truncate px-4 py-2.5 text-muted-foreground"
                            >
                                {{ c.source.description || '—' }}
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums">
                                {{ c.source.produits_count }}
                            </td>
                            <td class="px-4 py-2.5">
                                <StatusDot
                                    :status="c.source.statut"
                                    :label="c.source.statut_label"
                                />
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <div class="flex justify-end gap-0.5">
                                    <button
                                        type="button"
                                        :title="
                                            c.source.statut === 'actif'
                                                ? 'Désactiver'
                                                : 'Activer'
                                        "
                                        class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                        @click="toggle(c.source)"
                                    >
                                        <Power class="h-3.5 w-3.5" />
                                    </button>
                                    <button
                                        type="button"
                                        title="Modifier"
                                        class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                        @click="openEdit(c.source)"
                                    >
                                        <Pencil class="h-3.5 w-3.5" />
                                    </button>
                                    <button
                                        type="button"
                                        title="Supprimer"
                                        class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                                        @click="destroy(c.source)"
                                    >
                                        <Trash2 class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>

    <!-- Dialog Create / Edit -->
    <Dialog
        v-model:visible="showDialog"
        modal
        :header="dialogTitle"
        :style="{ width: 'min(28rem, 95vw)' }"
        :draggable="false"
        @hide="form.clearErrors()"
    >
        <div class="space-y-4">
            <div class="space-y-1.5">
                <Label for="cat-nom"
                    >Nom <span class="text-destructive">*</span></Label
                >
                <InputText
                    id="cat-nom"
                    v-model="form.nom"
                    class="w-full"
                    :class="form.errors.nom ? 'p-invalid' : ''"
                    autofocus
                />
                <p v-if="form.errors.nom" class="text-xs text-destructive">
                    {{ form.errors.nom }}
                </p>
            </div>

            <div class="space-y-1.5">
                <Label for="cat-parent">Catégorie parente</Label>
                <Dropdown
                    v-model="form.parent_id"
                    input-id="cat-parent"
                    :options="parentOptions"
                    option-label="label"
                    option-value="id"
                    filter
                    show-clear
                    placeholder="Aucune (catégorie racine)"
                    class="w-full"
                    :class="form.errors.parent_id ? 'p-invalid' : ''"
                >
                    <template #option="{ option }">
                        <span
                            :style="{ paddingLeft: `${option.depth * 16}px` }"
                            >{{ option.displayLabel }}</span
                        >
                    </template>
                </Dropdown>
                <p
                    v-if="form.errors.parent_id"
                    class="text-xs text-destructive"
                >
                    {{ form.errors.parent_id }}
                </p>
            </div>

            <div class="space-y-1.5">
                <Label for="cat-description">Description</Label>
                <Textarea
                    id="cat-description"
                    v-model="form.description"
                    placeholder="Facultatif"
                    rows="2"
                    class="w-full"
                    auto-resize
                />
            </div>

            <div class="flex items-center gap-3">
                <Checkbox
                    id="cat-active"
                    :model-value="form.statut === 'actif'"
                    @update:model-value="
                        form.statut = $event === true ? 'actif' : 'inactif'
                    "
                />
                <div>
                    <Label for="cat-active" class="cursor-pointer font-medium"
                        >Catégorie active</Label
                    >
                    <p class="text-xs text-muted-foreground">
                        Une catégorie inactive n'est plus proposée à la
                        sélection sur un produit.
                    </p>
                </div>
            </div>
        </div>

        <template #footer>
            <div class="flex justify-end gap-2">
                <Button variant="outline" @click="showDialog = false"
                    >Annuler</Button
                >
                <Button :disabled="form.processing" @click="handleSubmit">
                    {{ editingCategorie ? 'Enregistrer' : 'Créer' }}
                </Button>
            </div>
        </template>
    </Dialog>
</template>
