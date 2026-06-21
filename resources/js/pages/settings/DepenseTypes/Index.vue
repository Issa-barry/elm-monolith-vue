<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Power, Tags, Trash2 } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Dialog from 'primevue/dialog';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

interface Option {
    value: string;
    label: string;
}
interface DepenseType {
    id: string;
    libelle: string;
    description: string | null;
    categorie: string;
    categorie_label: string;
    commentaire_obligatoire: boolean;
    justificatif_obligatoire: boolean;
    type_paie: string | null;
    is_active: boolean;
    depenses_count: number;
}

const props = defineProps<{
    types: DepenseType[];
    categories: Option[];
}>();

const toast = useToast();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Paramètres', href: '/settings/profile' },
    { title: 'Types de dépense', href: '/settings/depense-types' },
];

const search = ref('');
const selectedCategorie = ref('');
const selectedStatut = ref('');

const filterFields = computed((): FilterField[] => [
    {
        key: 'categorie',
        label: 'Concerné',
        type: 'select',
        options: props.categories,
    },
    {
        key: 'statut',
        label: 'Statut',
        type: 'select',
        options: [
            { value: 'actif', label: 'Actif' },
            { value: 'inactif', label: 'Inactif' },
        ],
    },
]);

function handleApply(values: Record<string, unknown>) {
    selectedCategorie.value = (values.categorie as string) || '';
    selectedStatut.value = (values.statut as string) || '';
}

function resetFilters() {
    search.value = '';
    selectedCategorie.value = '';
    selectedStatut.value = '';
}

const filtered = computed(() => {
    let data = props.types;
    if (selectedCategorie.value) {
        data = data.filter((t) => t.categorie === selectedCategorie.value);
    }
    if (selectedStatut.value) {
        data = data.filter((t) =>
            selectedStatut.value === 'actif' ? t.is_active : !t.is_active,
        );
    }
    const q = search.value.trim().toLowerCase();
    if (!q) return data;
    return data.filter(
        (t) =>
            t.libelle.toLowerCase().includes(q) ||
            t.categorie_label.toLowerCase().includes(q) ||
            (t.description ?? '').toLowerCase().includes(q),
    );
});

// ── Dialog Create / Edit ──────────────────────────────────────────────────────

const showDialog = ref(false);
const editingType = ref<DepenseType | null>(null);

const dialogTitle = computed(() =>
    editingType.value ? 'Modifier le type' : 'Nouveau type de dépense',
);

const form = useForm({
    libelle: '',
    description: '',
    categorie: 'interne',
    commentaire_obligatoire: false,
    justificatif_obligatoire: false,
    type_paie: '' as string,
    is_active: true,
});

function openCreate() {
    editingType.value = null;
    form.reset();
    form.is_active = true;
    form.categorie = 'interne';
    showDialog.value = true;
}

function openEdit(type: DepenseType) {
    editingType.value = type;
    form.libelle = type.libelle;
    form.description = type.description ?? '';
    form.categorie = type.categorie;
    form.commentaire_obligatoire = type.commentaire_obligatoire;
    form.justificatif_obligatoire = type.justificatif_obligatoire;
    form.type_paie = type.type_paie ?? '';
    form.is_active = type.is_active;
    showDialog.value = true;
}

function handleSubmit() {
    if (editingType.value) {
        form.put(`/settings/depense-types/${editingType.value.id}`, {
            onSuccess: () => {
                showDialog.value = false;
                toast.add({
                    severity: 'success',
                    summary: 'Type mis à jour',
                    life: 3000,
                });
            },
        });
    } else {
        form.post('/settings/depense-types', {
            onSuccess: () => {
                showDialog.value = false;
                form.reset();
                toast.add({
                    severity: 'success',
                    summary: 'Type créé',
                    life: 3000,
                });
            },
        });
    }
}

// ── Actions ───────────────────────────────────────────────────────────────────

function toggle(type: DepenseType) {
    router.patch(
        `/settings/depense-types/${type.id}/toggle`,
        {},
        { preserveScroll: true },
    );
}

function destroy(type: DepenseType) {
    if (type.depenses_count > 0) {
        toast.add({
            severity: 'warn',
            summary: 'Suppression impossible',
            detail: `Ce type est utilisé dans ${type.depenses_count} dépense${type.depenses_count > 1 ? 's' : ''}. Désactivez-le plutôt.`,
            life: 5000,
        });
        return;
    }
    if (!confirm(`Supprimer le type « ${type.libelle} » ?`)) return;
    router.delete(`/settings/depense-types/${type.id}`, {
        preserveScroll: true,
    });
}

const categorieColors: Record<string, string> = {
    interne:
        'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    employe: 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300',
    livreur:
        'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300',
    proprietaire:
        'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300',
    vehicule:
        'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300',
};
</script>

<template>
    <Head title="Types de dépense" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingsLayout :wide="true">
            <div class="space-y-6">
                <div class="flex items-start justify-between gap-4">
                    <HeadingSmall
                        title="Types de dépense"
                        description="Classement des dépenses par concerné : véhicule, livreur, salarié, propriétaire ou interne."
                    />
                    <Button size="sm" @click="openCreate">
                        <Plus class="mr-1.5 h-3.5 w-3.5" />
                        Nouveau type
                    </Button>
                </div>

                <!-- Filtres -->
                <DataFilters
                    :fields="filterFields"
                    :values="{
                        categorie: selectedCategorie,
                        statut: selectedStatut,
                    }"
                    :result-count="filtered.length"
                    search-placeholder="Rechercher…"
                    v-model:search="search"
                    @apply="handleApply"
                    @reset="resetFilters"
                />

                <!-- DataTable -->
                <div class="overflow-hidden rounded-xl border bg-card">
                    <DataTable
                        :value="filtered"
                        :paginator="filtered.length > 15"
                        :rows="15"
                        data-key="id"
                        striped-rows
                        removable-sort
                        class="text-sm"
                        :pt="{
                            root: { class: 'w-full' },
                            tbody: { class: 'divide-y' },
                        }"
                    >
                        <!-- Libellé -->
                        <Column
                            field="libelle"
                            header="Libellé"
                            sortable
                            style="min-width: 220px"
                        >
                            <template #body="{ data }">
                                <div
                                    class="flex items-center gap-3"
                                    :class="{ 'opacity-50': !data.is_active }"
                                >
                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border bg-muted/30"
                                    >
                                        <Tags
                                            class="h-4 w-4 text-muted-foreground"
                                        />
                                    </div>
                                    <span class="font-medium">{{
                                        data.libelle
                                    }}</span>
                                </div>
                            </template>
                        </Column>

                        <!-- Concerné -->
                        <Column
                            field="categorie_label"
                            header="Concerné"
                            sortable
                            style="width: 140px"
                        >
                            <template #body="{ data }">
                                <span
                                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                    :class="
                                        categorieColors[data.categorie] ??
                                        'bg-muted text-muted-foreground'
                                    "
                                >
                                    {{ data.categorie_label }}
                                </span>
                            </template>
                        </Column>

                        <!-- Commentaire obligatoire -->
                        <Column
                            header="Commentaire"
                            style="width: 120px; text-align: center"
                        >
                            <template #body="{ data }">
                                <div class="flex justify-center">
                                    <span
                                        v-if="data.commentaire_obligatoire"
                                        class="text-xs font-medium text-amber-600"
                                        >Requis</span
                                    >
                                    <span
                                        v-else
                                        class="text-xs text-muted-foreground"
                                        >—</span
                                    >
                                </div>
                            </template>
                        </Column>

                        <!-- Justificatif obligatoire -->
                        <Column
                            header="Justificatif"
                            style="width: 120px; text-align: center"
                        >
                            <template #body="{ data }">
                                <div class="flex justify-center">
                                    <span
                                        v-if="data.justificatif_obligatoire"
                                        class="text-xs font-medium text-amber-600"
                                        >Requis</span
                                    >
                                    <span
                                        v-else
                                        class="text-xs text-muted-foreground"
                                        >—</span
                                    >
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
                                <Badge
                                    :variant="
                                        data.is_active ? 'default' : 'secondary'
                                    "
                                >
                                    {{ data.is_active ? 'Actif' : 'Inactif' }}
                                </Badge>
                            </template>
                        </Column>

                        <!-- Actions -->
                        <Column header="" style="width: 110px">
                            <template #body="{ data }">
                                <div class="flex justify-end gap-0.5">
                                    <button
                                        type="button"
                                        :title="
                                            data.is_active
                                                ? 'Désactiver'
                                                : 'Activer'
                                        "
                                        class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                        @click="toggle(data)"
                                    >
                                        <Power class="h-3.5 w-3.5" />
                                    </button>
                                    <button
                                        type="button"
                                        title="Modifier"
                                        class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                        @click="openEdit(data)"
                                    >
                                        <Pencil class="h-3.5 w-3.5" />
                                    </button>
                                    <button
                                        type="button"
                                        title="Supprimer"
                                        class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                                        @click="destroy(data)"
                                    >
                                        <Trash2 class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            </template>
                        </Column>

                        <template #empty>
                            <div
                                class="flex flex-col items-center gap-3 py-12 text-muted-foreground"
                            >
                                <Tags class="h-10 w-10 opacity-30" />
                                <p class="text-sm">Aucun type de dépense.</p>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="openCreate"
                                >
                                    <Plus class="mr-2 h-4 w-4" />
                                    Créer le premier type
                                </Button>
                            </div>
                        </template>
                    </DataTable>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>

    <!-- Dialog Create / Edit -->
    <Dialog
        v-model:visible="showDialog"
        modal
        :header="dialogTitle"
        :style="{ width: 'min(560px, 95vw)' }"
        :dismissable-mask="true"
    >
        <form class="space-y-4 pt-2 pb-1" @submit.prevent="handleSubmit">
            <!-- Libellé -->
            <div>
                <Label
                    for="dt-libelle"
                    class="mb-1.5 block text-xs font-medium"
                >
                    Libellé <span class="text-destructive">*</span>
                </Label>
                <Input
                    id="dt-libelle"
                    v-model="form.libelle"
                    placeholder="ex: Carburant véhicule"
                    :class="{ 'border-destructive': form.errors.libelle }"
                />
                <p
                    v-if="form.errors.libelle"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ form.errors.libelle }}
                </p>
            </div>

            <!-- Concerné -->
            <div>
                <Label
                    for="dt-categorie"
                    class="mb-1.5 block text-xs font-medium"
                >
                    Concerné <span class="text-destructive">*</span>
                </Label>
                <select
                    id="dt-categorie"
                    v-model="form.categorie"
                    class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm"
                    :class="{ 'border-destructive': form.errors.categorie }"
                >
                    <option
                        v-for="c in categories"
                        :key="c.value"
                        :value="c.value"
                    >
                        {{ c.label }}
                    </option>
                </select>
                <p
                    v-if="form.errors.categorie"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ form.errors.categorie }}
                </p>
            </div>

            <!-- Description -->
            <div>
                <Label
                    for="dt-description"
                    class="mb-1.5 block text-xs font-medium"
                    >Description</Label
                >
                <textarea
                    id="dt-description"
                    v-model="form.description"
                    placeholder="Description optionnelle…"
                    rows="2"
                    class="flex min-h-[60px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                />
            </div>

            <!-- Options -->
            <div class="space-y-2.5 rounded-lg border bg-muted/30 p-3">
                <p class="text-xs font-medium text-muted-foreground">Options</p>
                <div class="flex items-center gap-3">
                    <Checkbox
                        id="dt-comment"
                        :model-value="form.commentaire_obligatoire"
                        @update:model-value="
                            form.commentaire_obligatoire = $event === true
                        "
                    />
                    <div>
                        <Label
                            for="dt-comment"
                            class="cursor-pointer text-sm font-medium"
                            >Commentaire obligatoire</Label
                        >
                        <p class="text-xs text-muted-foreground">
                            Un commentaire devra être saisi.
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <Checkbox
                        id="dt-justif"
                        :model-value="form.justificatif_obligatoire"
                        @update:model-value="
                            form.justificatif_obligatoire = $event === true
                        "
                    />
                    <div>
                        <Label
                            for="dt-justif"
                            class="cursor-pointer text-sm font-medium"
                            >Justificatif obligatoire</Label
                        >
                        <p class="text-xs text-muted-foreground">
                            Un justificatif (reçu, photo) devra être fourni.
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <Checkbox
                        id="dt-active"
                        :model-value="form.is_active"
                        @update:model-value="form.is_active = $event === true"
                    />
                    <div>
                        <Label
                            for="dt-active"
                            class="cursor-pointer text-sm font-medium"
                            >Actif</Label
                        >
                        <p class="text-xs text-muted-foreground">
                            Un type inactif ne peut pas être utilisé sur une
                            nouvelle dépense.
                        </p>
                    </div>
                </div>
            </div>

            <div class="flex justify-between pt-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="showDialog = false"
                    >Annuler</Button
                >
                <Button type="submit" size="sm" :disabled="form.processing">
                    {{
                        form.processing
                            ? 'Enregistrement…'
                            : editingType
                              ? 'Enregistrer'
                              : 'Créer'
                    }}
                </Button>
            </div>
        </form>
    </Dialog>
</template>
