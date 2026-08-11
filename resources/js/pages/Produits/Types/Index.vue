<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Check,
    Layers,
    Pencil,
    Plus,
    Power,
    Trash2,
    X,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputText from 'primevue/inputtext';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, ref, watch } from 'vue';

interface ProduitTypeRow {
    id: string;
    nom: string;
    code: string;
    statut: string;
    statut_label: string;
    gere_stock: boolean;
    vendable: boolean;
    achetable: boolean;
    prix_achat_requis: boolean;
    prix_usine_requis: boolean;
    prix_vente_requis: boolean;
    champ_prix_reference: string | null;
    position: number;
    produits_count: number;
    is_used: boolean;
}

const props = defineProps<{
    types: ProduitTypeRow[];
    statuts: { value: string; label: string }[];
}>();

const toast = useToast();
const confirm = useConfirm();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Produits', href: '/backoffice/produits' },
    { title: 'Types', href: '/backoffice/produits/types' },
];

// ── Filtres ──────────────────────────────────────────────────────────────────

const search = ref('');
const selectedStatut = ref('');

const filterFields = computed((): FilterField[] => [
    {
        key: 'search',
        label: 'Recherche',
        type: 'text',
        placeholder: 'Nom…',
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

const filtered = computed(() => {
    let data = [...props.types].sort((a, b) => a.position - b.position);
    if (selectedStatut.value) {
        data = data.filter((t) => t.statut === selectedStatut.value);
    }
    const q = search.value.trim().toLowerCase();
    if (q) {
        data = data.filter((t) => t.nom.toLowerCase().includes(q));
    }

    return data;
});

// ── Dialog Create / Edit ──────────────────────────────────────────────────────

const showDialog = ref(false);
const editingType = ref<ProduitTypeRow | null>(null);

const dialogTitle = computed(() =>
    editingType.value ? 'Modifier le type' : 'Créer un type de produit',
);

const form = useForm({
    nom: '',
    statut: 'actif',
    gere_stock: true,
    vendable: true,
    achetable: false,
    prix_achat_requis: false,
    prix_usine_requis: false,
    prix_vente_requis: false,
    champ_prix_reference: null as string | null,
});

// Le champ de référence pour la marge doit être un des prix requis — on le vide
// automatiquement si l'utilisateur décoche le prix qui le portait (évite un état
// incohérent bloqué en validation serveur sans que l'utilisateur comprenne pourquoi).
watch(
    () => [form.prix_achat_requis, form.prix_usine_requis],
    () => {
        if (
            form.champ_prix_reference === 'prix_achat' &&
            !form.prix_achat_requis
        ) {
            form.champ_prix_reference = null;
        }
        if (
            form.champ_prix_reference === 'prix_usine' &&
            !form.prix_usine_requis
        ) {
            form.champ_prix_reference = null;
        }
    },
);

const referenceOptions = computed(() => {
    const opts: { value: string; label: string }[] = [];
    if (form.prix_achat_requis)
        opts.push({ value: 'prix_achat', label: "Prix d'achat" });
    if (form.prix_usine_requis)
        opts.push({ value: 'prix_usine', label: 'Prix usine' });

    return opts;
});

function openCreate() {
    editingType.value = null;
    form.reset();
    form.statut = 'actif';
    showDialog.value = true;
}

function openEdit(type: ProduitTypeRow) {
    editingType.value = type;
    form.nom = type.nom;
    form.statut = type.statut;
    form.gere_stock = type.gere_stock;
    form.vendable = type.vendable;
    form.achetable = type.achetable;
    form.prix_achat_requis = type.prix_achat_requis;
    form.prix_usine_requis = type.prix_usine_requis;
    form.prix_vente_requis = type.prix_vente_requis;
    form.champ_prix_reference = type.champ_prix_reference;
    showDialog.value = true;
}

function handleSubmit() {
    if (editingType.value) {
        form.put(`/backoffice/produits/types/${editingType.value.id}`, {
            preserveScroll: true,
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
        form.post('/backoffice/produits/types', {
            preserveScroll: true,
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

function toggle(type: ProduitTypeRow) {
    router.patch(
        `/backoffice/produits/types/${type.id}/toggle`,
        {},
        { preserveScroll: true },
    );
}

function destroy(type: ProduitTypeRow) {
    if (type.is_used) {
        toast.add({
            severity: 'warn',
            summary: 'Suppression impossible',
            detail: `Ce type est utilisé par ${type.produits_count} produit${type.produits_count > 1 ? 's' : ''}. Désactivez-le plutôt.`,
            life: 5000,
        });
        return;
    }
    confirm.require({
        message: `Supprimer le type « ${type.nom} » ?`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(`/backoffice/produits/types/${type.id}`, {
                preserveScroll: true,
                onSuccess: () =>
                    toast.add({
                        severity: 'success',
                        summary: 'Supprimé',
                        detail: `« ${type.nom} » a été supprimé.`,
                        life: 3000,
                    }),
            });
        },
    });
}
</script>

<template>
    <Head title="Types de produit" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-5xl space-y-6 p-4 sm:p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Types de produit
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Définit le comportement de chaque produit : suivi de
                        stock, achat/vente possibles, prix requis.
                    </p>
                </div>
                <Button size="sm" @click="openCreate">
                    <Plus class="mr-1.5 h-3.5 w-3.5" />
                    Nouveau type
                </Button>
            </div>

            <DataFilters
                :fields="filterFields"
                :values="{ search, statut: selectedStatut }"
                :result-count="filtered.length"
                :hide-agence-selector="true"
                @apply="handleApply"
                @reset="resetFilters"
            />

            <div class="overflow-hidden rounded-xl border bg-card">
                <div v-if="filtered.length === 0" class="p-4">
                    <div
                        class="flex flex-col items-center gap-3 py-12 text-muted-foreground"
                    >
                        <Layers class="h-10 w-10 opacity-30" />
                        <p class="text-sm">
                            {{
                                types.length === 0
                                    ? 'Aucun type créé.'
                                    : 'Aucun type ne correspond à ces filtres.'
                            }}
                        </p>
                    </div>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b text-xs text-muted-foreground">
                                <th class="px-4 py-2.5 text-left font-medium">
                                    Nom
                                </th>
                                <th
                                    class="px-4 py-2.5 text-center font-medium"
                                >
                                    Gère du stock
                                </th>
                                <th
                                    class="px-4 py-2.5 text-center font-medium"
                                >
                                    Vendable
                                </th>
                                <th
                                    class="px-4 py-2.5 text-center font-medium"
                                >
                                    Achetable
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
                                v-for="t in filtered"
                                :key="t.id"
                                :class="{ 'opacity-50': t.statut !== 'actif' }"
                            >
                                <td class="px-4 py-2.5 font-medium">
                                    {{ t.nom }}
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <Check
                                        v-if="t.gere_stock"
                                        class="mx-auto h-4 w-4 text-emerald-600"
                                    />
                                    <X
                                        v-else
                                        class="mx-auto h-4 w-4 text-muted-foreground/40"
                                    />
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <Check
                                        v-if="t.vendable"
                                        class="mx-auto h-4 w-4 text-emerald-600"
                                    />
                                    <X
                                        v-else
                                        class="mx-auto h-4 w-4 text-muted-foreground/40"
                                    />
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <Check
                                        v-if="t.achetable"
                                        class="mx-auto h-4 w-4 text-emerald-600"
                                    />
                                    <X
                                        v-else
                                        class="mx-auto h-4 w-4 text-muted-foreground/40"
                                    />
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums">
                                    {{ t.produits_count }}
                                </td>
                                <td class="px-4 py-2.5">
                                    <StatusDot
                                        :status="t.statut"
                                        :label="t.statut_label"
                                    />
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    <div class="flex justify-end gap-0.5">
                                        <button
                                            type="button"
                                            :title="
                                                t.statut === 'actif'
                                                    ? 'Désactiver'
                                                    : 'Activer'
                                            "
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                            @click="toggle(t)"
                                        >
                                            <Power class="h-3.5 w-3.5" />
                                        </button>
                                        <button
                                            type="button"
                                            title="Modifier"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                            @click="openEdit(t)"
                                        >
                                            <Pencil class="h-3.5 w-3.5" />
                                        </button>
                                        <button
                                            type="button"
                                            title="Supprimer"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                                            @click="destroy(t)"
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
        </div>
    </AppLayout>

    <!-- Dialog Create / Edit -->
    <Dialog
        v-model:visible="showDialog"
        modal
        :header="dialogTitle"
        :style="{ width: 'min(32rem, 95vw)' }"
        :draggable="false"
        @hide="form.clearErrors()"
    >
        <div class="space-y-4">
            <div
                v-if="editingType?.is_used"
                class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-400"
            >
                Ce type est utilisé par {{ editingType.produits_count }}
                produit{{ editingType.produits_count > 1 ? 's' : '' }} : sa
                structure (stock, prix requis, marge) ne peut plus être
                modifiée. Seuls le nom, le statut et l'ordre restent
                modifiables.
            </div>
            <p
                v-if="form.errors.structure"
                class="text-xs text-destructive"
            >
                {{ form.errors.structure }}
            </p>

            <div class="space-y-1.5">
                <Label for="type-nom"
                    >Nom <span class="text-destructive">*</span></Label
                >
                <InputText
                    id="type-nom"
                    v-model="form.nom"
                    class="w-full"
                    :class="form.errors.nom ? 'p-invalid' : ''"
                    autofocus
                />
                <p v-if="form.errors.nom" class="text-xs text-destructive">
                    {{ form.errors.nom }}
                </p>
            </div>

            <div
                class="space-y-3 rounded-lg border p-3"
                :class="{ 'opacity-60': editingType?.is_used }"
            >
                <div class="flex items-center gap-3">
                    <Checkbox
                        id="type-gere-stock"
                        :model-value="form.gere_stock"
                        :disabled="editingType?.is_used"
                        @update:model-value="
                            form.gere_stock = $event === true
                        "
                    />
                    <div>
                        <Label
                            for="type-gere-stock"
                            class="cursor-pointer font-medium"
                            >Gère du stock</Label
                        >
                        <p class="text-xs text-muted-foreground">
                            Les produits de ce type ont une quantité suivie
                            par site et peuvent déclencher une alerte de stock
                            faible.
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <Checkbox
                        id="type-vendable"
                        :model-value="form.vendable"
                        :disabled="editingType?.is_used"
                        @update:model-value="form.vendable = $event === true"
                    />
                    <Label
                        for="type-vendable"
                        class="cursor-pointer text-sm font-medium"
                        >Vendable (proposé en vente/PDV)</Label
                    >
                </div>

                <div class="flex items-center gap-3">
                    <Checkbox
                        id="type-achetable"
                        :model-value="form.achetable"
                        :disabled="editingType?.is_used"
                        @update:model-value="form.achetable = $event === true"
                    />
                    <Label
                        for="type-achetable"
                        class="cursor-pointer text-sm font-medium"
                        >Achetable (proposé en commande d'achat)</Label
                    >
                </div>
            </div>

            <div
                class="space-y-3 rounded-lg border p-3"
                :class="{ 'opacity-60': editingType?.is_used }"
            >
                <p class="text-xs font-semibold text-muted-foreground uppercase">
                    Prix obligatoires
                </p>
                <div class="flex items-center gap-3">
                    <Checkbox
                        id="type-prix-achat"
                        :model-value="form.prix_achat_requis"
                        :disabled="editingType?.is_used"
                        @update:model-value="
                            form.prix_achat_requis = $event === true
                        "
                    />
                    <Label
                        for="type-prix-achat"
                        class="cursor-pointer text-sm"
                        >Prix d'achat requis</Label
                    >
                </div>
                <div class="flex items-center gap-3">
                    <Checkbox
                        id="type-prix-usine"
                        :model-value="form.prix_usine_requis"
                        :disabled="editingType?.is_used"
                        @update:model-value="
                            form.prix_usine_requis = $event === true
                        "
                    />
                    <Label
                        for="type-prix-usine"
                        class="cursor-pointer text-sm"
                        >Prix usine requis</Label
                    >
                </div>
                <div class="flex items-center gap-3">
                    <Checkbox
                        id="type-prix-vente"
                        :model-value="form.prix_vente_requis"
                        :disabled="editingType?.is_used"
                        @update:model-value="
                            form.prix_vente_requis = $event === true
                        "
                    />
                    <Label
                        for="type-prix-vente"
                        class="cursor-pointer text-sm"
                        >Prix de vente requis</Label
                    >
                </div>

                <div v-if="referenceOptions.length > 0" class="space-y-1.5">
                    <Label for="type-reference"
                        >Référence pour le contrôle de marge</Label
                    >
                    <Dropdown
                        v-model="form.champ_prix_reference"
                        input-id="type-reference"
                        :options="referenceOptions"
                        option-label="label"
                        option-value="value"
                        show-clear
                        :disabled="editingType?.is_used"
                        placeholder="Aucune (pas de contrôle de marge)"
                        class="w-full"
                    />
                    <p class="text-xs text-muted-foreground">
                        Si renseigné, ELM refuse tout prix de vente inférieur
                        ou égal à ce prix de référence — garde-fou anti-vente
                        à perte, toujours appliqué par le système.
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <Checkbox
                    id="type-active"
                    :model-value="form.statut === 'actif'"
                    @update:model-value="
                        form.statut = $event === true ? 'actif' : 'inactif'
                    "
                />
                <div>
                    <Label for="type-active" class="cursor-pointer font-medium"
                        >Type actif</Label
                    >
                    <p class="text-xs text-muted-foreground">
                        Un type inactif n'est plus proposé à la création d'un
                        nouveau produit.
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
                    {{ editingType ? 'Enregistrer' : 'Créer' }}
                </Button>
            </div>
        </template>
    </Dialog>
</template>
