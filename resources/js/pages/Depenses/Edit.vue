<script setup lang="ts">
import BeneficiairePickerDialog from '@/components/Depenses/BeneficiairePickerDialog.vue';
import DepenseSummaryCard from '@/components/Depenses/DepenseSummaryCard.vue';
import type { PickerField } from '@/components/Depenses/pickerTypes';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { Lock, Search, X } from 'lucide-vue-next';
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
    telephone?: string | null;
    site_nom?: string | null;
    vehicule_noms?: string | null;
    vehicule_immatriculations?: string | null;
}
interface SiteOption {
    id: string;
    nom: string;
}
interface DepenseData {
    id: string;
    depense_type_id: string;
    beneficiaire_type: string | null;
    beneficiaire_id: string | null;
    site_id: string | null;
    montant: number;
    date_depense: string;
    commentaire: string;
    statut: string;
}

const props = defineProps<{
    depense: DepenseData;
    types: TypeOption[];
    vehicules: Vehicule[];
    sites: SiteOption[];
    employes: PersonneOption[];
    livreurs: PersonneOption[];
    proprietaires: PersonneOption[];
    prestataires: PersonneOption[];
    clients: PersonneOption[];
    categories: { value: string; label: string }[];
    can_change_site: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dépenses', href: '/backoffice/depenses' },
    { title: 'Modifier la dépense', href: '#' },
];

// ── Concerné — pré-sélectionné depuis le type existant ───────────────────────
const initialCategorie =
    props.types.find((t) => t.id === props.depense.depense_type_id)
        ?.categorie ?? '';
const concerneSelectionne = ref(initialCategorie);

watch(concerneSelectionne, (newVal, oldVal) => {
    if (newVal !== oldVal) {
        form.depense_type_id = '';
        form.beneficiaire_id = '';
        vehiculeSelected.value = null;
        employeSelected.value = null;
        livreurSelected.value = null;
        proprietaireSelected.value = null;
        prestataireSelected.value = null;
        clientSelected.value = null;
    }
});

const typesFiltres = computed<TypeOption[]>(() =>
    concerneSelectionne.value
        ? props.types.filter((t) => t.categorie === concerneSelectionne.value)
        : [],
);

// ── Formulaire ────────────────────────────────────────────────────────────────
const form = useForm({
    depense_type_id: props.depense.depense_type_id,
    beneficiaire_id: props.depense.beneficiaire_id ?? '',
    site_id: props.depense.site_id ?? '',
    montant: props.depense.montant as number | '',
    date_depense: props.depense.date_depense,
    commentaire: props.depense.commentaire,
    statut: 'brouillon' as 'brouillon' | 'soumis',
});

const selectedType = computed<TypeOption | null>(
    () => typesFiltres.value.find((t) => t.id === form.depense_type_id) ?? null,
);

watch(
    () => form.depense_type_id,
    (newVal, oldVal) => {
        if (newVal !== oldVal) {
            form.beneficiaire_id = '';
            vehiculeSelected.value = null;
            employeSelected.value = null;
            livreurSelected.value = null;
            proprietaireSelected.value = null;
            prestataireSelected.value = null;
            clientSelected.value = null;
        }
    },
);

// ── Véhicule — pré-sélectionné si applicable, recherche via modale ──────────
const vehiculeSelected = ref<Vehicule | null>(
    props.depense.beneficiaire_type === 'vehicule' &&
        props.depense.beneficiaire_id
        ? (props.vehicules.find(
              (v) => v.id === props.depense.beneficiaire_id,
          ) ?? null)
        : null,
);
const showVehiculePicker = ref(false);

const vehiculeFields: PickerField<Vehicule>[] = [
    { key: 'nom', label: 'Nom du véhicule', value: (v) => v.nom_vehicule },
    {
        key: 'immatriculation',
        label: 'Immatriculation',
        value: (v) => v.immatriculation,
    },
];

function onVehiculeSelect(v: Vehicule) {
    vehiculeSelected.value = v;
    form.beneficiaire_id = v.id;
}

function clearVehicule() {
    vehiculeSelected.value = null;
    form.beneficiaire_id = '';
}

// ── Employé / Livreur / Propriétaire / Prestataire — recherche via modale ───
// Un champ par critère (nom/prénom, téléphone, et — selon le type — site ou
// véhicule/immatriculation) plutôt qu'une recherche combinée.
const nomField: PickerField<PersonneOption> = {
    key: 'nom',
    label: 'Nom / Prénom',
    value: (p) => `${p.nom_complet} ${p.matricule ?? ''}`.trim(),
};
const telephoneField: PickerField<PersonneOption> = {
    key: 'telephone',
    label: 'Téléphone',
    value: (p) => p.telephone,
    phone: true,
};

const employeSelected = ref<PersonneOption | null>(
    props.depense.beneficiaire_type === 'employe' &&
        props.depense.beneficiaire_id
        ? (props.employes.find((e) => e.id === props.depense.beneficiaire_id) ??
              null)
        : null,
);
const showEmployePicker = ref(false);
const employeFields: PickerField<PersonneOption>[] = [
    nomField,
    telephoneField,
    { key: 'site', label: 'Site', value: (p) => p.site_nom },
];

function onEmployeSelect(e: PersonneOption) {
    employeSelected.value = e;
    form.beneficiaire_id = e.id;
}

function clearEmploye() {
    employeSelected.value = null;
    form.beneficiaire_id = '';
}

const livreurSelected = ref<PersonneOption | null>(
    props.depense.beneficiaire_type === 'livreur' &&
        props.depense.beneficiaire_id
        ? (props.livreurs.find((l) => l.id === props.depense.beneficiaire_id) ??
              null)
        : null,
);
const showLivreurPicker = ref(false);
const livreurFields: PickerField<PersonneOption>[] = [
    nomField,
    telephoneField,
    { key: 'vehicule', label: 'Véhicule', value: (p) => p.vehicule_noms },
    {
        key: 'immatriculation',
        label: 'Immatriculation',
        value: (p) => p.vehicule_immatriculations,
    },
];

function onLivreurSelect(l: PersonneOption) {
    livreurSelected.value = l;
    form.beneficiaire_id = l.id;
}

function clearLivreur() {
    livreurSelected.value = null;
    form.beneficiaire_id = '';
}

const proprietaireSelected = ref<PersonneOption | null>(
    props.depense.beneficiaire_type === 'proprietaire' &&
        props.depense.beneficiaire_id
        ? (props.proprietaires.find(
              (p) => p.id === props.depense.beneficiaire_id,
          ) ?? null)
        : null,
);
const showProprietairePicker = ref(false);
const proprietaireFields: PickerField<PersonneOption>[] = [
    nomField,
    telephoneField,
    { key: 'vehicule', label: 'Véhicule', value: (p) => p.vehicule_noms },
    {
        key: 'immatriculation',
        label: 'Immatriculation',
        value: (p) => p.vehicule_immatriculations,
    },
];

function onProprietaireSelect(p: PersonneOption) {
    proprietaireSelected.value = p;
    form.beneficiaire_id = p.id;
}

function clearProprietaire() {
    proprietaireSelected.value = null;
    form.beneficiaire_id = '';
}

const prestataireSelected = ref<PersonneOption | null>(
    props.depense.beneficiaire_type === 'prestataire' &&
        props.depense.beneficiaire_id
        ? (props.prestataires.find(
              (p) => p.id === props.depense.beneficiaire_id,
          ) ?? null)
        : null,
);
const showPrestatairePicker = ref(false);
const prestataireFields: PickerField<PersonneOption>[] = [
    { key: 'nom', label: 'Nom / Entreprise', value: (p) => p.nom_complet },
    telephoneField,
];

function onPrestataireSelect(p: PersonneOption) {
    prestataireSelected.value = p;
    form.beneficiaire_id = p.id;
}

function clearPrestataire() {
    prestataireSelected.value = null;
    form.beneficiaire_id = '';
}

const clientSelected = ref<PersonneOption | null>(
    props.depense.beneficiaire_type === 'client' &&
        props.depense.beneficiaire_id
        ? (props.clients.find(
              (client) => client.id === props.depense.beneficiaire_id,
          ) ?? null)
        : null,
);
const showClientPicker = ref(false);
const clientFields: PickerField<PersonneOption>[] = [nomField, telephoneField];

function onClientSelect(client: PersonneOption) {
    clientSelected.value = client;
    form.beneficiaire_id = client.id;
}

function clearClient() {
    clientSelected.value = null;
    form.beneficiaire_id = '';
}

// ── Computed pour la fiche récapitulative ─────────────────────────────────────
const categorie = computed(
    () => selectedType.value?.categorie ?? concerneSelectionne.value ?? null,
);

const categorieLabel = computed(
    () =>
        props.categories.find((c) => c.value === concerneSelectionne.value)
            ?.label ?? null,
);

type VehiculeContext =
    | 'interne'
    | 'externe_avec_proprietaire'
    | 'externe_sans_proprietaire'
    | null;

const vehiculeContext = computed<VehiculeContext>(() => {
    const v = vehiculeSelected.value;
    if (!v) return null;
    if (v.categorie === 'interne') return 'interne';
    return v.has_proprietaire
        ? 'externe_avec_proprietaire'
        : 'externe_sans_proprietaire';
});

const beneficiaireLabel = computed<string | null>(() => {
    if (!form.beneficiaire_id) return null;
    const cat = categorie.value;
    if (cat === 'employe')
        return (
            props.employes.find((e) => e.id === form.beneficiaire_id)
                ?.nom_complet ?? null
        );
    if (cat === 'livreur')
        return (
            props.livreurs.find((l) => l.id === form.beneficiaire_id)
                ?.nom_complet ?? null
        );
    if (cat === 'proprietaire')
        return (
            props.proprietaires.find((p) => p.id === form.beneficiaire_id)
                ?.nom_complet ?? null
        );
    if (cat === 'prestataire')
        return (
            props.prestataires.find((p) => p.id === form.beneficiaire_id)
                ?.nom_complet ?? null
        );
    if (cat === 'client')
        return (
            props.clients.find((client) => client.id === form.beneficiaire_id)
                ?.nom_complet ?? null
        );
    return null;
});

const siteNom = computed(
    () => props.sites.find((s) => s.id === form.site_id)?.nom ?? null,
);

const concerneBadgeClass = computed(() => {
    const map: Record<string, string> = {
        vehicule: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        proprietaire: 'border-purple-200 bg-purple-50 text-purple-700',
        livreur: 'border-amber-200 bg-amber-50 text-amber-700',
        employe: 'border-blue-200 bg-blue-50 text-blue-700',
        interne: 'border-slate-200 bg-slate-50 text-slate-700',
        prestataire: 'border-teal-200 bg-teal-50 text-teal-700',
        client: 'border-indigo-200 bg-indigo-50 text-indigo-700',
    };
    return map[concerneSelectionne.value] ?? '';
});

const concerneLabel = computed(
    () =>
        props.categories.find((c) => c.value === concerneSelectionne.value)
            ?.label ?? '',
);

// ── Montant formaté ───────────────────────────────────────────────────────────
const montantDisplay = ref(
    props.depense.montant
        ? Number(props.depense.montant).toLocaleString('fr-FR', {
              maximumFractionDigits: 0,
          })
        : '',
);

function handleMontantInput(e: Event) {
    const raw = (e.target as HTMLInputElement).value.replace(/\D/g, '');
    form.montant = raw ? parseInt(raw, 10) : '';
    montantDisplay.value = raw
        ? parseInt(raw, 10).toLocaleString('fr-FR', {
              maximumFractionDigits: 0,
          })
        : '';
}

// ── Soumission ────────────────────────────────────────────────────────────────
const toast = useToast();

function submitAs(statut: 'brouillon' | 'soumis') {
    form.statut = statut;
    form.put(`/backoffice/depenses/${props.depense.id}`, {
        onSuccess: () => {
            toast.add({
                severity: 'success',
                summary:
                    statut === 'brouillon'
                        ? 'Dépense enregistrée en brouillon'
                        : 'Dépense soumise pour validation',
                life: 4000,
            });
        },
    });
}
</script>

<template>
    <Head title="Modifier la dépense" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-4 sm:p-6">
            <div class="mx-auto max-w-5xl">
                <!-- Header -->
                <div class="mb-6">
                    <h1 class="text-xl font-semibold">Modifier la dépense</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Statut actuel : {{ depense.statut }}
                    </p>
                </div>

                <!-- Grid 2 colonnes -->
                <div class="grid gap-6 lg:grid-cols-[3fr_2fr] lg:items-start">
                    <!-- ── COLONNE GAUCHE : Formulaire ─────────────────────── -->
                    <form class="space-y-4" @submit.prevent>
                        <!-- Concerné -->
                        <div class="space-y-3 rounded-xl border bg-card p-4">
                            <h2
                                class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                Concerné
                            </h2>
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                <label
                                    v-for="cat in categories"
                                    :key="cat.value"
                                    class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm transition-colors"
                                    :class="
                                        concerneSelectionne === cat.value
                                            ? `${concerneBadgeClass} font-medium ring-1 ring-current`
                                            : 'hover:bg-muted/40'
                                    "
                                >
                                    <input
                                        v-model="concerneSelectionne"
                                        type="radio"
                                        :value="cat.value"
                                        class="sr-only"
                                    />
                                    <span
                                        class="h-3 w-3 shrink-0 rounded-full border-2 transition-colors"
                                        :class="
                                            concerneSelectionne === cat.value
                                                ? 'border-current bg-current'
                                                : 'border-muted-foreground'
                                        "
                                    />
                                    {{ cat.label }}
                                </label>
                            </div>
                        </div>

                        <!-- Type de dépense -->
                        <div class="space-y-3 rounded-xl border bg-card p-4">
                            <h2
                                class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                Type de dépense
                            </h2>
                            <div>
                                <Label
                                    for="dep-type"
                                    class="mb-1.5 block text-xs font-medium"
                                >
                                    Type <span class="text-destructive">*</span>
                                </Label>
                                <select
                                    id="dep-type"
                                    v-model="form.depense_type_id"
                                    :disabled="!concerneSelectionne"
                                    class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm transition-colors focus:ring-2 focus:ring-ring focus:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                    :class="{
                                        'border-destructive':
                                            form.errors.depense_type_id,
                                    }"
                                >
                                    <option value="">
                                        — Sélectionner un type —
                                    </option>
                                    <option
                                        v-for="t in typesFiltres"
                                        :key="t.id"
                                        :value="t.id"
                                    >
                                        {{ t.libelle }}
                                    </option>
                                </select>
                                <p
                                    v-if="form.errors.depense_type_id"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{ form.errors.depense_type_id }}
                                </p>
                            </div>
                        </div>

                        <!-- Bénéficiaire conditionnel -->
                        <div
                            v-if="selectedType && categorie !== 'interne'"
                            class="space-y-3 rounded-xl border bg-card p-4"
                        >
                            <h2
                                class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                {{ concerneLabel }}
                            </h2>

                            <!-- Véhicule -->
                            <div v-if="categorie === 'vehicule'">
                                <Label
                                    for="dep-vehicule"
                                    class="mb-1.5 block text-xs font-medium"
                                >
                                    Véhicule
                                    <span class="text-destructive">*</span>
                                </Label>
                                <div class="relative">
                                    <button
                                        id="dep-vehicule"
                                        type="button"
                                        class="flex h-9 w-full items-center rounded-md border border-input bg-background px-3 text-sm shadow-sm transition-colors hover:bg-muted/40"
                                        :class="[
                                            form.errors.beneficiaire_id
                                                ? 'border-destructive'
                                                : '',
                                            vehiculeSelected ? 'pr-8' : '',
                                        ]"
                                        @click="showVehiculePicker = true"
                                    >
                                        <Search
                                            class="mr-2 h-3.5 w-3.5 shrink-0 text-muted-foreground"
                                        />
                                        <span
                                            class="truncate text-left"
                                            :class="
                                                vehiculeSelected
                                                    ? ''
                                                    : 'text-muted-foreground'
                                            "
                                        >
                                            {{
                                                vehiculeSelected?.nom_vehicule ??
                                                'Rechercher un véhicule…'
                                            }}
                                        </span>
                                    </button>
                                    <button
                                        v-if="vehiculeSelected"
                                        type="button"
                                        class="absolute top-1/2 right-2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                        aria-label="Effacer la sélection"
                                        @click.stop="clearVehicule"
                                    >
                                        <X class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                                <p
                                    v-if="form.errors.beneficiaire_id"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{ form.errors.beneficiaire_id }}
                                </p>

                                <BeneficiairePickerDialog
                                    v-model:visible="showVehiculePicker"
                                    title="Sélectionner un véhicule"
                                    :options="vehicules"
                                    :fields="vehiculeFields"
                                    empty-label="Aucun véhicule trouvé"
                                    @select="onVehiculeSelect"
                                >
                                    <template #option="{ option }">
                                        <div class="leading-tight font-medium">
                                            {{ option.nom_vehicule }}
                                        </div>
                                        <div
                                            class="mt-0.5 font-mono text-xs text-muted-foreground"
                                        >
                                            {{ option.immatriculation }}
                                        </div>
                                        <div
                                            v-if="
                                                option.categorie === 'interne'
                                            "
                                            class="mt-0.5 text-xs text-blue-600"
                                        >
                                            ELM —
                                            {{ option.site_nom ?? 'interne' }}
                                        </div>
                                        <div
                                            v-else-if="option.has_proprietaire"
                                            class="mt-0.5 text-xs text-emerald-600"
                                        >
                                            ✓ {{ option.proprietaire_nom }}
                                        </div>
                                        <div
                                            v-else
                                            class="mt-0.5 text-xs text-amber-600"
                                        >
                                            ⚠ Aucun propriétaire rattaché
                                        </div>
                                    </template>
                                </BeneficiairePickerDialog>
                            </div>

                            <!-- Salarié -->
                            <div v-else-if="categorie === 'employe'">
                                <Label
                                    for="dep-employe"
                                    class="mb-1.5 block text-xs font-medium"
                                >
                                    Salarié
                                    <span class="text-destructive">*</span>
                                </Label>
                                <div class="relative">
                                    <button
                                        id="dep-employe"
                                        type="button"
                                        class="flex h-9 w-full items-center rounded-md border border-input bg-background px-3 text-sm shadow-sm transition-colors hover:bg-muted/40"
                                        :class="[
                                            form.errors.beneficiaire_id
                                                ? 'border-destructive'
                                                : '',
                                            employeSelected ? 'pr-8' : '',
                                        ]"
                                        @click="showEmployePicker = true"
                                    >
                                        <Search
                                            class="mr-2 h-3.5 w-3.5 shrink-0 text-muted-foreground"
                                        />
                                        <span
                                            class="truncate text-left"
                                            :class="
                                                employeSelected
                                                    ? ''
                                                    : 'text-muted-foreground'
                                            "
                                        >
                                            {{
                                                employeSelected?.nom_complet ??
                                                'Rechercher un salarié…'
                                            }}
                                        </span>
                                    </button>
                                    <button
                                        v-if="employeSelected"
                                        type="button"
                                        class="absolute top-1/2 right-2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                        aria-label="Effacer la sélection"
                                        @click.stop="clearEmploye"
                                    >
                                        <X class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                                <p
                                    v-if="form.errors.beneficiaire_id"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{ form.errors.beneficiaire_id }}
                                </p>

                                <BeneficiairePickerDialog
                                    v-model:visible="showEmployePicker"
                                    title="Sélectionner un salarié"
                                    :options="employes"
                                    :fields="employeFields"
                                    empty-label="Aucun salarié trouvé"
                                    @select="onEmployeSelect"
                                >
                                    <template #option="{ option }">
                                        <div class="leading-tight font-medium">
                                            {{ option.nom_complet
                                            }}{{
                                                option.matricule
                                                    ? ` — ${option.matricule}`
                                                    : ''
                                            }}
                                        </div>
                                        <div
                                            v-if="
                                                option.site_nom ||
                                                option.telephone
                                            "
                                            class="mt-0.5 text-xs text-muted-foreground"
                                        >
                                            {{
                                                [
                                                    option.site_nom,
                                                    option.telephone,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')
                                            }}
                                        </div>
                                    </template>
                                </BeneficiairePickerDialog>
                            </div>

                            <!-- Livreur -->
                            <div v-else-if="categorie === 'livreur'">
                                <Label
                                    for="dep-livreur"
                                    class="mb-1.5 block text-xs font-medium"
                                >
                                    Livreur
                                    <span class="text-destructive">*</span>
                                </Label>
                                <div class="relative">
                                    <button
                                        id="dep-livreur"
                                        type="button"
                                        class="flex h-9 w-full items-center rounded-md border border-input bg-background px-3 text-sm shadow-sm transition-colors hover:bg-muted/40"
                                        :class="[
                                            form.errors.beneficiaire_id
                                                ? 'border-destructive'
                                                : '',
                                            livreurSelected ? 'pr-8' : '',
                                        ]"
                                        @click="showLivreurPicker = true"
                                    >
                                        <Search
                                            class="mr-2 h-3.5 w-3.5 shrink-0 text-muted-foreground"
                                        />
                                        <span
                                            class="truncate text-left"
                                            :class="
                                                livreurSelected
                                                    ? ''
                                                    : 'text-muted-foreground'
                                            "
                                        >
                                            {{
                                                livreurSelected?.nom_complet ??
                                                'Rechercher un livreur…'
                                            }}
                                        </span>
                                    </button>
                                    <button
                                        v-if="livreurSelected"
                                        type="button"
                                        class="absolute top-1/2 right-2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                        aria-label="Effacer la sélection"
                                        @click.stop="clearLivreur"
                                    >
                                        <X class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                                <p
                                    v-if="form.errors.beneficiaire_id"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{ form.errors.beneficiaire_id }}
                                </p>

                                <BeneficiairePickerDialog
                                    v-model:visible="showLivreurPicker"
                                    title="Sélectionner un livreur"
                                    :options="livreurs"
                                    :fields="livreurFields"
                                    empty-label="Aucun livreur trouvé"
                                    @select="onLivreurSelect"
                                >
                                    <template #option="{ option }">
                                        <div class="leading-tight font-medium">
                                            {{ option.nom_complet }}
                                        </div>
                                        <div
                                            v-if="option.vehicule_noms"
                                            class="mt-0.5 text-xs text-muted-foreground"
                                        >
                                            🚚 {{ option.vehicule_noms }}
                                            <span
                                                v-if="
                                                    option.vehicule_immatriculations
                                                "
                                                class="font-mono"
                                                >—
                                                {{
                                                    option.vehicule_immatriculations
                                                }}</span
                                            >
                                        </div>
                                        <div
                                            v-if="option.telephone"
                                            class="mt-0.5 text-xs text-muted-foreground"
                                        >
                                            ☎ {{ option.telephone }}
                                        </div>
                                    </template>
                                </BeneficiairePickerDialog>
                            </div>

                            <!-- Propriétaire -->
                            <div v-else-if="categorie === 'proprietaire'">
                                <Label
                                    for="dep-proprio"
                                    class="mb-1.5 block text-xs font-medium"
                                >
                                    Propriétaire
                                    <span class="text-destructive">*</span>
                                </Label>
                                <div class="relative">
                                    <button
                                        id="dep-proprio"
                                        type="button"
                                        class="flex h-9 w-full items-center rounded-md border border-input bg-background px-3 text-sm shadow-sm transition-colors hover:bg-muted/40"
                                        :class="[
                                            form.errors.beneficiaire_id
                                                ? 'border-destructive'
                                                : '',
                                            proprietaireSelected ? 'pr-8' : '',
                                        ]"
                                        @click="showProprietairePicker = true"
                                    >
                                        <Search
                                            class="mr-2 h-3.5 w-3.5 shrink-0 text-muted-foreground"
                                        />
                                        <span
                                            class="truncate text-left"
                                            :class="
                                                proprietaireSelected
                                                    ? ''
                                                    : 'text-muted-foreground'
                                            "
                                        >
                                            {{
                                                proprietaireSelected?.nom_complet ??
                                                'Rechercher un propriétaire…'
                                            }}
                                        </span>
                                    </button>
                                    <button
                                        v-if="proprietaireSelected"
                                        type="button"
                                        class="absolute top-1/2 right-2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                        aria-label="Effacer la sélection"
                                        @click.stop="clearProprietaire"
                                    >
                                        <X class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                                <p
                                    v-if="form.errors.beneficiaire_id"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{ form.errors.beneficiaire_id }}
                                </p>

                                <BeneficiairePickerDialog
                                    v-model:visible="showProprietairePicker"
                                    title="Sélectionner un propriétaire"
                                    :options="proprietaires"
                                    :fields="proprietaireFields"
                                    empty-label="Aucun propriétaire trouvé"
                                    @select="onProprietaireSelect"
                                >
                                    <template #option="{ option }">
                                        <div class="leading-tight font-medium">
                                            {{ option.nom_complet }}
                                        </div>
                                        <div
                                            v-if="option.vehicule_noms"
                                            class="mt-0.5 text-xs text-muted-foreground"
                                        >
                                            🚚 {{ option.vehicule_noms }}
                                            <span
                                                v-if="
                                                    option.vehicule_immatriculations
                                                "
                                                class="font-mono"
                                                >—
                                                {{
                                                    option.vehicule_immatriculations
                                                }}</span
                                            >
                                        </div>
                                        <div
                                            v-if="option.telephone"
                                            class="mt-0.5 text-xs text-muted-foreground"
                                        >
                                            ☎ {{ option.telephone }}
                                        </div>
                                    </template>
                                </BeneficiairePickerDialog>
                            </div>

                            <!-- Prestataire -->
                            <div v-else-if="categorie === 'prestataire'">
                                <Label
                                    for="dep-prestataire"
                                    class="mb-1.5 block text-xs font-medium"
                                >
                                    Prestataire
                                    <span class="text-destructive">*</span>
                                </Label>
                                <div class="relative">
                                    <button
                                        id="dep-prestataire"
                                        type="button"
                                        class="flex h-9 w-full items-center rounded-md border border-input bg-background px-3 text-sm shadow-sm transition-colors hover:bg-muted/40"
                                        :class="[
                                            form.errors.beneficiaire_id
                                                ? 'border-destructive'
                                                : '',
                                            prestataireSelected ? 'pr-8' : '',
                                        ]"
                                        @click="showPrestatairePicker = true"
                                    >
                                        <Search
                                            class="mr-2 h-3.5 w-3.5 shrink-0 text-muted-foreground"
                                        />
                                        <span
                                            class="truncate text-left"
                                            :class="
                                                prestataireSelected
                                                    ? ''
                                                    : 'text-muted-foreground'
                                            "
                                        >
                                            {{
                                                prestataireSelected?.nom_complet ??
                                                'Rechercher un prestataire…'
                                            }}
                                        </span>
                                    </button>
                                    <button
                                        v-if="prestataireSelected"
                                        type="button"
                                        class="absolute top-1/2 right-2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                        aria-label="Effacer la sélection"
                                        @click.stop="clearPrestataire"
                                    >
                                        <X class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                                <p
                                    v-if="form.errors.beneficiaire_id"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{ form.errors.beneficiaire_id }}
                                </p>

                                <BeneficiairePickerDialog
                                    v-model:visible="showPrestatairePicker"
                                    title="Sélectionner un prestataire"
                                    :options="prestataires"
                                    :fields="prestataireFields"
                                    empty-label="Aucun prestataire trouvé"
                                    @select="onPrestataireSelect"
                                >
                                    <template #option="{ option }">
                                        <div class="leading-tight font-medium">
                                            {{ option.nom_complet }}
                                        </div>
                                        <div
                                            v-if="option.telephone"
                                            class="mt-0.5 text-xs text-muted-foreground"
                                        >
                                            ☎ {{ option.telephone }}
                                        </div>
                                    </template>
                                </BeneficiairePickerDialog>
                            </div>

                            <!-- Client -->
                            <div v-else-if="categorie === 'client'">
                                <Label
                                    for="dep-client"
                                    class="mb-1.5 block text-xs font-medium"
                                >
                                    Client
                                    <span class="text-destructive">*</span>
                                </Label>
                                <div class="relative">
                                    <button
                                        id="dep-client"
                                        type="button"
                                        class="flex h-9 w-full items-center rounded-md border border-input bg-background px-3 text-sm shadow-sm transition-colors hover:bg-muted/40"
                                        :class="[
                                            form.errors.beneficiaire_id
                                                ? 'border-destructive'
                                                : '',
                                            clientSelected ? 'pr-8' : '',
                                        ]"
                                        @click="showClientPicker = true"
                                    >
                                        <Search
                                            class="mr-2 h-3.5 w-3.5 shrink-0 text-muted-foreground"
                                        />
                                        <span
                                            class="truncate text-left"
                                            :class="
                                                clientSelected
                                                    ? ''
                                                    : 'text-muted-foreground'
                                            "
                                        >
                                            {{
                                                clientSelected?.nom_complet ??
                                                'Rechercher un client…'
                                            }}
                                        </span>
                                    </button>
                                    <button
                                        v-if="clientSelected"
                                        type="button"
                                        class="absolute top-1/2 right-2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                        aria-label="Effacer la sélection"
                                        @click.stop="clearClient"
                                    >
                                        <X class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                                <p
                                    v-if="form.errors.beneficiaire_id"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{ form.errors.beneficiaire_id }}
                                </p>

                                <BeneficiairePickerDialog
                                    v-model:visible="showClientPicker"
                                    title="Sélectionner un client"
                                    :options="clients"
                                    :fields="clientFields"
                                    empty-label="Aucun client trouvé"
                                    @select="onClientSelect"
                                >
                                    <template #option="{ option }">
                                        <div class="leading-tight font-medium">
                                            {{ option.nom_complet }}
                                        </div>
                                        <div
                                            v-if="option.telephone"
                                            class="mt-0.5 text-xs text-muted-foreground"
                                        >
                                            ☎ {{ option.telephone }}
                                        </div>
                                    </template>
                                </BeneficiairePickerDialog>
                            </div>
                        </div>

                        <!-- Détails -->
                        <div
                            v-if="selectedType"
                            class="space-y-4 rounded-xl border bg-card p-4"
                        >
                            <h2
                                class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                Détails
                            </h2>

                            <div class="grid grid-cols-3 gap-3">
                                <div>
                                    <Label
                                        for="dep-montant"
                                        class="mb-1.5 block text-xs font-medium"
                                    >
                                        Montant (GNF)
                                        <span class="text-destructive">*</span>
                                    </Label>
                                    <input
                                        id="dep-montant"
                                        :value="montantDisplay"
                                        type="text"
                                        inputmode="numeric"
                                        class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm font-bold tabular-nums shadow-sm transition-colors placeholder:font-normal placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        :class="{
                                            'border-destructive':
                                                form.errors.montant,
                                        }"
                                        @input="handleMontantInput"
                                    />
                                    <p
                                        v-if="form.errors.montant"
                                        class="mt-1 text-xs text-destructive"
                                    >
                                        {{ form.errors.montant }}
                                    </p>
                                </div>
                                <div>
                                    <Label
                                        for="dep-date"
                                        class="mb-1.5 block text-xs font-medium"
                                    >
                                        Date
                                        <span class="text-destructive">*</span>
                                    </Label>
                                    <Input
                                        id="dep-date"
                                        v-model="form.date_depense"
                                        type="date"
                                        :class="{
                                            'border-destructive':
                                                form.errors.date_depense,
                                        }"
                                    />
                                    <p
                                        v-if="form.errors.date_depense"
                                        class="mt-1 text-xs text-destructive"
                                    >
                                        {{ form.errors.date_depense }}
                                    </p>
                                </div>
                                <div>
                                    <Label
                                        for="dep-site"
                                        class="mb-1.5 flex items-center gap-1 text-xs font-medium"
                                    >
                                        Site
                                        <Lock
                                            v-if="!can_change_site"
                                            class="h-3 w-3 text-muted-foreground"
                                        />
                                    </Label>
                                    <select
                                        id="dep-site"
                                        v-model="form.site_id"
                                        :disabled="!can_change_site"
                                        class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <option value="">
                                            Aucun site spécifique
                                        </option>
                                        <option
                                            v-for="s in sites"
                                            :key="s.id"
                                            :value="s.id"
                                        >
                                            {{ s.nom }}
                                        </option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <Label
                                    for="dep-comment"
                                    class="mb-1.5 block text-xs font-medium"
                                >
                                    Commentaire
                                    <span
                                        v-if="
                                            selectedType.commentaire_obligatoire
                                        "
                                        class="text-destructive"
                                        >*</span
                                    >
                                </Label>
                                <textarea
                                    id="dep-comment"
                                    v-model="form.commentaire"
                                    rows="3"
                                    placeholder="Détails de la dépense…"
                                    class="flex min-h-[72px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                                    :class="{
                                        'border-destructive':
                                            form.errors.commentaire,
                                    }"
                                />
                                <p
                                    v-if="form.errors.commentaire"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{ form.errors.commentaire }}
                                </p>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex justify-between pt-1">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                as-child
                            >
                                <a :href="`/backoffice/depenses/${depense.id}`"
                                    >Annuler</a
                                >
                            </Button>
                            <div class="flex gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :disabled="
                                        form.processing || !form.depense_type_id
                                    "
                                    @click="submitAs('brouillon')"
                                >
                                    {{
                                        form.processing &&
                                        form.statut === 'brouillon'
                                            ? 'Enregistrement…'
                                            : 'Enregistrer comme brouillon'
                                    }}
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    :disabled="
                                        form.processing || !form.depense_type_id
                                    "
                                    @click="submitAs('soumis')"
                                >
                                    {{
                                        form.processing &&
                                        form.statut === 'soumis'
                                            ? 'Envoi…'
                                            : 'Soumettre pour validation'
                                    }}
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
