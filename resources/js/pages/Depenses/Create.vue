<script setup lang="ts">
import DepenseSummaryCard from '@/components/Depenses/DepenseSummaryCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import AutoComplete from 'primevue/autocomplete';
import { useToast } from 'primevue/usetoast';
import { computed, ref, watch } from 'vue';

interface TypeOption {
    id: string;
    code: string;
    libelle: string;
    categorie: string;
    categorie_label: string;
    impact_message: string;
    commentaire_obligatoire: boolean;
    justificatif_obligatoire: boolean;
}
interface Vehicule {
    id: string;
    nom_vehicule: string;
    immatriculation: string;
    categorie: string;
    site_nom: string | null;
    proprietaire_nom: string | null;
    has_proprietaire: boolean;
}
interface PersonneOption {
    id: string;
    nom_complet: string;
    matricule?: string | null;
}
interface SiteOption {
    id: string;
    nom: string;
}

const props = defineProps<{
    types: TypeOption[];
    vehicules: Vehicule[];
    sites: SiteOption[];
    employes: PersonneOption[];
    livreurs: PersonneOption[];
    proprietaires: PersonneOption[];
    default_site_id: string | null;
    categories: { value: string; label: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dépenses', href: '/depenses' },
    { title: 'Nouvelle dépense', href: '/depenses/create' },
];

// ── Concerné ─────────────────────────────────────────────────────────────────
const concerneSelectionne = ref('');

watch(concerneSelectionne, () => {
    form.depense_type_id = '';
    form.beneficiaire_id = '';
    vehiculeSelected.value = null;
});

const typesFiltres = computed<TypeOption[]>(() =>
    concerneSelectionne.value
        ? props.types.filter((t) => t.categorie === concerneSelectionne.value)
        : [],
);

// ── Formulaire ────────────────────────────────────────────────────────────────
const form = useForm({
    depense_type_id: '',
    beneficiaire_id: '',
    site_id: props.default_site_id ?? '',
    montant: '' as number | '',
    date_depense: new Date().toISOString().slice(0, 10),
    commentaire: '',
    statut: 'brouillon' as 'brouillon' | 'soumis',
});

const selectedType = computed<TypeOption | null>(
    () => typesFiltres.value.find((t) => t.id === form.depense_type_id) ?? null,
);

watch(() => form.depense_type_id, () => {
    form.beneficiaire_id = '';
    vehiculeSelected.value = null;
});

// ── Véhicule AutoComplete ─────────────────────────────────────────────────────
const vehiculeSelected = ref<Vehicule | null>(null);
const vehiculeSuggests = ref<Vehicule[]>([]);

function searchVehicule(event: { query: string }) {
    const q = event.query.toLowerCase().trim();
    vehiculeSuggests.value = q
        ? props.vehicules.filter(
              (v) =>
                  v.nom_vehicule.toLowerCase().includes(q) ||
                  v.immatriculation.toLowerCase().includes(q),
          )
        : [...props.vehicules];
}

function onVehiculeSelect(v: Vehicule | null) {
    form.beneficiaire_id = v ? v.id : '';
}

// ── Computed pour la fiche récapitulative ─────────────────────────────────────
const categorie = computed(() => selectedType.value?.categorie ?? concerneSelectionne.value ?? null);

const categorieLabel = computed(
    () => props.categories.find((c) => c.value === concerneSelectionne.value)?.label ?? null,
);

type VehiculeContext = 'interne' | 'externe_avec_proprietaire' | 'externe_sans_proprietaire' | null;

const vehiculeContext = computed<VehiculeContext>(() => {
    const v = vehiculeSelected.value;
    if (!v) return null;
    if (v.categorie === 'interne') return 'interne';
    return v.has_proprietaire ? 'externe_avec_proprietaire' : 'externe_sans_proprietaire';
});

const beneficiaireLabel = computed<string | null>(() => {
    if (!form.beneficiaire_id) return null;
    const cat = categorie.value;
    if (cat === 'employe') return props.employes.find((e) => e.id === form.beneficiaire_id)?.nom_complet ?? null;
    if (cat === 'livreur') return props.livreurs.find((l) => l.id === form.beneficiaire_id)?.nom_complet ?? null;
    if (cat === 'proprietaire') return props.proprietaires.find((p) => p.id === form.beneficiaire_id)?.nom_complet ?? null;
    return null;
});

const siteNom = computed(() => props.sites.find((s) => s.id === form.site_id)?.nom ?? null);

const concerneBadgeClass = computed(() => {
    const map: Record<string, string> = {
        vehicule: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        proprietaire: 'border-purple-200 bg-purple-50 text-purple-700',
        livreur: 'border-amber-200 bg-amber-50 text-amber-700',
        employe: 'border-blue-200 bg-blue-50 text-blue-700',
        interne: 'border-slate-200 bg-slate-50 text-slate-700',
    };
    return map[concerneSelectionne.value] ?? '';
});

const concerneLabel = computed(
    () => props.categories.find((c) => c.value === concerneSelectionne.value)?.label ?? '',
);

// ── Soumission ────────────────────────────────────────────────────────────────
const toast = useToast();

function submitAs(statut: 'brouillon' | 'soumis') {
    form.statut = statut;
    form.post('/depenses', {
        forceFormData: false,
        onSuccess: () => {
            toast.add({
                severity: 'success',
                summary: statut === 'brouillon' ? 'Dépense enregistrée en brouillon' : 'Dépense soumise pour validation',
                life: 4000,
            });
        },
    });
}
</script>

<template>
    <Head title="Nouvelle dépense" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-4 sm:p-6">
            <div class="mx-auto max-w-5xl">

                <!-- Header -->
                <div class="mb-6">
                    <h1 class="text-xl font-semibold">Nouvelle dépense</h1>
                    <p class="mt-1 text-sm text-muted-foreground">Sélectionnez d'abord le concerné.</p>
                </div>

                <!-- Grid 2 colonnes -->
                <div class="grid gap-6 lg:grid-cols-[3fr_2fr] lg:items-start">

                    <!-- ── COLONNE GAUCHE : Formulaire ─────────────────────── -->
                    <form class="space-y-4" @submit.prevent>

                        <!-- Concerné -->
                        <div class="rounded-xl border bg-card p-4 space-y-3">
                            <h2 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Concerné</h2>
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                <label
                                    v-for="cat in categories"
                                    :key="cat.value"
                                    class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm transition-colors"
                                    :class="concerneSelectionne === cat.value
                                        ? `${concerneBadgeClass} font-medium ring-1 ring-current`
                                        : 'hover:bg-muted/40'"
                                >
                                    <input
                                        v-model="concerneSelectionne"
                                        type="radio"
                                        :value="cat.value"
                                        class="sr-only"
                                    />
                                    <span
                                        class="h-3 w-3 shrink-0 rounded-full border-2 transition-colors"
                                        :class="concerneSelectionne === cat.value ? 'border-current bg-current' : 'border-muted-foreground'"
                                    />
                                    {{ cat.label }}
                                </label>
                            </div>
                        </div>

                        <!-- Type de dépense -->
                        <div class="rounded-xl border bg-card p-4 space-y-3">
                            <h2 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Type de dépense</h2>
                            <div>
                                <Label for="dep-type" class="mb-1.5 block text-xs font-medium">
                                    Type <span class="text-destructive">*</span>
                                </Label>
                                <select
                                    id="dep-type"
                                    v-model="form.depense_type_id"
                                    :disabled="!concerneSelectionne"
                                    class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                                    :class="{ 'border-destructive': form.errors.depense_type_id }"
                                >
                                    <option value="">
                                        {{ concerneSelectionne ? '— Sélectionner un type —' : '— Choisissez d\'abord un concerné —' }}
                                    </option>
                                    <option v-for="t in typesFiltres" :key="t.id" :value="t.id">
                                        {{ t.libelle }}
                                    </option>
                                </select>
                                <p v-if="form.errors.depense_type_id" class="mt-1 text-xs text-destructive">
                                    {{ form.errors.depense_type_id }}
                                </p>
                                <p v-if="concerneSelectionne && typesFiltres.length === 0" class="mt-1 text-xs text-amber-600">
                                    Aucun type actif pour ce concerné. Ajoutez-en dans les paramètres.
                                </p>
                            </div>
                        </div>

                        <!-- Bénéficiaire conditionnel -->
                        <div
                            v-if="selectedType && categorie !== 'interne'"
                            class="rounded-xl border bg-card p-4 space-y-3"
                        >
                            <h2 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                {{ concerneLabel }}
                            </h2>

                            <!-- Véhicule -->
                            <div v-if="categorie === 'vehicule'">
                                <Label for="dep-vehicule" class="mb-1.5 block text-xs font-medium">
                                    Véhicule <span class="text-destructive">*</span>
                                </Label>
                                <AutoComplete
                                    v-model="vehiculeSelected"
                                    input-id="dep-vehicule"
                                    :suggestions="vehiculeSuggests"
                                    option-label="nom_vehicule"
                                    placeholder="Rechercher un véhicule…"
                                    class="w-full"
                                    input-class="w-full"
                                    :class="{ 'p-invalid': form.errors.beneficiaire_id }"
                                    dropdown
                                    force-selection
                                    @complete="searchVehicule"
                                    @item-select="onVehiculeSelect(vehiculeSelected)"
                                    @clear="() => { vehiculeSelected = null; form.beneficiaire_id = ''; }"
                                >
                                    <template #option="{ option }">
                                        <div class="py-0.5">
                                            <div class="font-medium leading-tight">{{ option.nom_vehicule }}</div>
                                            <div class="mt-0.5 font-mono text-xs text-muted-foreground">{{ option.immatriculation }}</div>
                                            <div v-if="option.categorie === 'interne'" class="mt-0.5 text-xs text-blue-600">
                                                ELM — {{ option.site_nom ?? 'interne' }}
                                            </div>
                                            <div v-else-if="option.has_proprietaire" class="mt-0.5 text-xs text-emerald-600">
                                                ✓ {{ option.proprietaire_nom }}
                                            </div>
                                            <div v-else class="mt-0.5 text-xs text-amber-600">
                                                ⚠ Aucun propriétaire rattaché
                                            </div>
                                        </div>
                                    </template>
                                    <template #empty>
                                        <div class="px-1 py-0.5 text-sm text-muted-foreground">Aucun véhicule trouvé</div>
                                    </template>
                                </AutoComplete>
                                <p v-if="form.errors.beneficiaire_id" class="mt-1 text-xs text-destructive">
                                    {{ form.errors.beneficiaire_id }}
                                </p>
                            </div>

                            <!-- Salarié -->
                            <div v-else-if="categorie === 'employe'">
                                <Label for="dep-employe" class="mb-1.5 block text-xs font-medium">
                                    Salarié <span class="text-destructive">*</span>
                                </Label>
                                <select
                                    id="dep-employe"
                                    v-model="form.beneficiaire_id"
                                    class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm"
                                    :class="{ 'border-destructive': form.errors.beneficiaire_id }"
                                >
                                    <option value="">— Sélectionner un salarié —</option>
                                    <option v-for="e in employes" :key="e.id" :value="e.id">
                                        {{ e.nom_complet }}{{ e.matricule ? ` — ${e.matricule}` : '' }}
                                    </option>
                                </select>
                                <p v-if="form.errors.beneficiaire_id" class="mt-1 text-xs text-destructive">
                                    {{ form.errors.beneficiaire_id }}
                                </p>
                            </div>

                            <!-- Livreur -->
                            <div v-else-if="categorie === 'livreur'">
                                <Label for="dep-livreur" class="mb-1.5 block text-xs font-medium">
                                    Livreur <span class="text-destructive">*</span>
                                </Label>
                                <select
                                    id="dep-livreur"
                                    v-model="form.beneficiaire_id"
                                    class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm"
                                    :class="{ 'border-destructive': form.errors.beneficiaire_id }"
                                >
                                    <option value="">— Sélectionner un livreur —</option>
                                    <option v-for="l in livreurs" :key="l.id" :value="l.id">
                                        {{ l.nom_complet }}
                                    </option>
                                </select>
                                <p v-if="form.errors.beneficiaire_id" class="mt-1 text-xs text-destructive">
                                    {{ form.errors.beneficiaire_id }}
                                </p>
                            </div>

                            <!-- Propriétaire -->
                            <div v-else-if="categorie === 'proprietaire'">
                                <Label for="dep-proprio" class="mb-1.5 block text-xs font-medium">
                                    Propriétaire <span class="text-destructive">*</span>
                                </Label>
                                <select
                                    id="dep-proprio"
                                    v-model="form.beneficiaire_id"
                                    class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm"
                                    :class="{ 'border-destructive': form.errors.beneficiaire_id }"
                                >
                                    <option value="">— Sélectionner un propriétaire —</option>
                                    <option v-for="p in proprietaires" :key="p.id" :value="p.id">
                                        {{ p.nom_complet }}
                                    </option>
                                </select>
                                <p v-if="form.errors.beneficiaire_id" class="mt-1 text-xs text-destructive">
                                    {{ form.errors.beneficiaire_id }}
                                </p>
                            </div>
                        </div>

                        <!-- Détails -->
                        <div v-if="selectedType" class="rounded-xl border bg-card p-4 space-y-4">
                            <h2 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Détails</h2>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <Label for="dep-montant" class="mb-1.5 block text-xs font-medium">
                                        Montant (GNF) <span class="text-destructive">*</span>
                                    </Label>
                                    <Input
                                        id="dep-montant"
                                        v-model.number="form.montant"
                                        type="number"
                                        min="1"
                                        step="1"
                                        placeholder="0"
                                        :class="{ 'border-destructive': form.errors.montant }"
                                    />
                                    <p v-if="form.errors.montant" class="mt-1 text-xs text-destructive">
                                        {{ form.errors.montant }}
                                    </p>
                                </div>
                                <div>
                                    <Label for="dep-site" class="mb-1.5 block text-xs font-medium">Site</Label>
                                    <select
                                        id="dep-site"
                                        v-model="form.site_id"
                                        class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm"
                                    >
                                        <option value="">Aucun site spécifique</option>
                                        <option v-for="s in sites" :key="s.id" :value="s.id">{{ s.nom }}</option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <Label for="dep-comment" class="mb-1.5 block text-xs font-medium">
                                    Commentaire
                                    <span v-if="selectedType.commentaire_obligatoire" class="text-destructive">*</span>
                                </Label>
                                <textarea
                                    id="dep-comment"
                                    v-model="form.commentaire"
                                    rows="3"
                                    placeholder="Détails de la dépense…"
                                    class="flex min-h-[72px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                                    :class="{ 'border-destructive': form.errors.commentaire }"
                                />
                                <p v-if="form.errors.commentaire" class="mt-1 text-xs text-destructive">
                                    {{ form.errors.commentaire }}
                                </p>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex justify-between pt-1">
                            <Button type="button" variant="outline" size="sm" as-child>
                                <a href="/depenses">Annuler</a>
                            </Button>
                            <div class="flex gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :disabled="form.processing || !form.depense_type_id"
                                    @click="submitAs('brouillon')"
                                >
                                    {{ form.processing && form.statut === 'brouillon' ? 'Enregistrement…' : 'Enregistrer comme brouillon' }}
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    :disabled="form.processing || !form.depense_type_id"
                                    @click="submitAs('soumis')"
                                >
                                    {{ form.processing && form.statut === 'soumis' ? 'Envoi…' : 'Soumettre pour validation' }}
                                </Button>
                            </div>
                        </div>
                    </form>

                    <!-- ── COLONNE DROITE : Fiche récapitulative ──────────── -->
                    <div class="lg:sticky lg:top-4">
                        <DepenseSummaryCard
                            :categorie="categorie"
                            :categorie-label="categorieLabel"
                            :type="selectedType"
                            :vehicule="vehiculeSelected"
                            :vehicule-context="vehiculeContext"
                            :beneficiaire-label="beneficiaireLabel"
                            :site-nom="siteNom"
                            :montant="form.montant"
                            :commentaire="form.commentaire"
                        />
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
