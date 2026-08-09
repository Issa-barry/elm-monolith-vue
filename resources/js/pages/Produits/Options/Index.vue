<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ListTree, Pencil, Plus, Trash2, X } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, reactive, ref } from 'vue';

interface OptionCatalogueValeur {
    id: string;
    valeur: string;
    position: number;
}

interface OptionCatalogue {
    id: string;
    nom: string;
    position: number;
    valeurs: OptionCatalogueValeur[];
}

const props = defineProps<{
    options: OptionCatalogue[];
}>();

const toast = useToast();
const confirm = useConfirm();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Produits', href: '/backoffice/produits' },
    { title: 'Options', href: '/backoffice/produits/options' },
];

// ── Filtres ──────────────────────────────────────────────────────────────────

const search = ref('');

const filterFields = computed((): FilterField[] => [
    {
        key: 'search',
        label: 'Recherche',
        type: 'text',
        placeholder: 'Nom, valeur…',
        inline: true,
    },
]);

function handleApply(values: Record<string, unknown>) {
    search.value = (values.search as string) ?? '';
}

function resetFilters() {
    search.value = '';
}

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.options;

    return props.options.filter(
        (o) =>
            o.nom.toLowerCase().includes(q) ||
            o.valeurs.some((v) => v.valeur.toLowerCase().includes(q)),
    );
});

// ── Dialog Create / Edit (nom uniquement — les valeurs se gèrent en ligne) ─────

const showDialog = ref(false);
const editingOption = ref<OptionCatalogue | null>(null);

const dialogTitle = computed(() =>
    editingOption.value ? "Renommer l'option" : 'Créer une option',
);

const form = useForm({
    nom: '',
});

function openCreate() {
    editingOption.value = null;
    form.reset();
    showDialog.value = true;
}

function openEdit(option: OptionCatalogue) {
    editingOption.value = option;
    form.nom = option.nom;
    showDialog.value = true;
}

function handleSubmit() {
    if (editingOption.value) {
        form.put(`/backoffice/produits/options/${editingOption.value.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                showDialog.value = false;
                toast.add({
                    severity: 'success',
                    summary: 'Option mise à jour',
                    life: 3000,
                });
            },
        });
    } else {
        form.post('/backoffice/produits/options', {
            preserveScroll: true,
            onSuccess: () => {
                showDialog.value = false;
                form.reset();
                toast.add({
                    severity: 'success',
                    summary: 'Option créée',
                    life: 3000,
                });
            },
        });
    }
}

function destroy(option: OptionCatalogue) {
    confirm.require({
        message: `Supprimer l'option « ${option.nom} » et ses ${option.valeurs.length} valeur(s) ? Les produits qui l'utilisent déjà ne sont pas affectés.`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(`/backoffice/produits/options/${option.id}`, {
                preserveScroll: true,
                onSuccess: () =>
                    toast.add({
                        severity: 'success',
                        summary: 'Supprimée',
                        detail: `« ${option.nom} » a été supprimée.`,
                        life: 3000,
                    }),
            });
        },
    });
}

// ── Valeurs (ajout/suppression en ligne) ────────────────────────────────────

const nouvellesValeurs = reactive<Record<string, string>>({});

function ajouterValeur(option: OptionCatalogue) {
    const valeur = (nouvellesValeurs[option.id] ?? '').trim();
    if (!valeur) return;

    router.post(
        `/backoffice/produits/options/${option.id}/valeurs`,
        { valeur },
        {
            preserveScroll: true,
            onSuccess: () => {
                nouvellesValeurs[option.id] = '';
            },
        },
    );
}

function supprimerValeur(option: OptionCatalogue, valeur: OptionCatalogueValeur) {
    router.delete(
        `/backoffice/produits/options/${option.id}/valeurs/${valeur.id}`,
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Options" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-5xl space-y-6 p-4 sm:p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Options
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Catalogue d'options réutilisables (Couleur, Taille,
                        Pointure…) — sert de bibliothèque de suggestions pour
                        vos produits, sans jamais modifier leurs déclinaisons
                        déjà générées.
                    </p>
                </div>
                <Button size="sm" @click="openCreate">
                    <Plus class="mr-1.5 h-3.5 w-3.5" />
                    Nouvelle option
                </Button>
            </div>

            <!-- Filtres -->
            <DataFilters
                :fields="filterFields"
                :values="{ search }"
                :result-count="filtered.length"
                :hide-agence-selector="true"
                @apply="handleApply"
                @reset="resetFilters"
            />

            <!-- Liste -->
            <div v-if="filtered.length === 0" class="rounded-xl border bg-card p-4">
                <div
                    class="flex flex-col items-center gap-3 py-12 text-muted-foreground"
                >
                    <ListTree class="h-10 w-10 opacity-30" />
                    <p class="text-sm">
                        {{
                            options.length === 0
                                ? 'Aucune option créée.'
                                : 'Aucune option ne correspond à ces filtres.'
                        }}
                    </p>
                    <Button
                        v-if="options.length === 0"
                        variant="outline"
                        size="sm"
                        @click="openCreate"
                    >
                        <Plus class="mr-2 h-4 w-4" />
                        Créer la première option
                    </Button>
                </div>
            </div>

            <div v-else class="space-y-3">
                <div
                    v-for="option in filtered"
                    :key="option.id"
                    class="rounded-xl border bg-card p-4 shadow-sm sm:p-5"
                >
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <h3 class="font-medium">{{ option.nom }}</h3>
                        <div class="flex gap-0.5">
                            <button
                                type="button"
                                title="Renommer"
                                class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                @click="openEdit(option)"
                            >
                                <Pencil class="h-3.5 w-3.5" />
                            </button>
                            <button
                                type="button"
                                title="Supprimer"
                                class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                                @click="destroy(option)"
                            >
                                <Trash2 class="h-3.5 w-3.5" />
                            </button>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <span
                            v-for="valeur in option.valeurs"
                            :key="valeur.id"
                            class="inline-flex items-center gap-1.5 rounded-md border bg-muted/40 px-2.5 py-1 text-sm"
                        >
                            {{ valeur.valeur }}
                            <button
                                type="button"
                                :aria-label="`Supprimer ${valeur.valeur}`"
                                class="text-muted-foreground hover:text-destructive"
                                @click="supprimerValeur(option, valeur)"
                            >
                                <X class="h-3 w-3" />
                            </button>
                        </span>
                        <span
                            v-if="option.valeurs.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            Aucune valeur proposée.
                        </span>
                    </div>

                    <div class="mt-3 flex items-center gap-2">
                        <InputText
                            v-model="nouvellesValeurs[option.id]"
                            placeholder="+ Ajouter une valeur"
                            class="h-8 max-w-[12rem] text-sm"
                            @keyup.enter="ajouterValeur(option)"
                        />
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="ajouterValeur(option)"
                        >
                            Ajouter
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>

    <!-- Dialog Create / Edit (nom) -->
    <Dialog
        v-model:visible="showDialog"
        modal
        :header="dialogTitle"
        :style="{ width: 'min(24rem, 95vw)' }"
        :draggable="false"
        @hide="form.clearErrors()"
    >
        <div class="space-y-1.5">
            <Label for="option-nom"
                >Nom <span class="text-destructive">*</span></Label
            >
            <InputText
                id="option-nom"
                v-model="form.nom"
                placeholder="Ex : Couleur, Taille, Pointure…"
                class="w-full"
                :class="form.errors.nom ? 'p-invalid' : ''"
                autofocus
                @keyup.enter="handleSubmit"
            />
            <p v-if="form.errors.nom" class="text-xs text-destructive">
                {{ form.errors.nom }}
            </p>
        </div>

        <template #footer>
            <div class="flex justify-end gap-2">
                <Button variant="outline" @click="showDialog = false"
                    >Annuler</Button
                >
                <Button :disabled="form.processing" @click="handleSubmit">
                    {{ editingOption ? 'Enregistrer' : 'Créer' }}
                </Button>
            </div>
        </template>
    </Dialog>
</template>
