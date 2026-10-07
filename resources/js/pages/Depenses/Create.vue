<script setup lang="ts">
import BeneficiairePickerDialog from '@/components/Depenses/BeneficiairePickerDialog.vue';
import DepenseConfirmDialog from '@/components/Depenses/DepenseConfirmDialog.vue';
import type { PickerField } from '@/components/Depenses/pickerTypes';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Lock, Search, X } from 'lucide-vue-next';
import Select from 'primevue/select';
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

const props = defineProps<{
    types: TypeOption[];
    vehicules: Vehicule[];
    sites: SiteOption[];
    employes: PersonneOption[];
    livreurs: PersonneOption[];
    proprietaires: PersonneOption[];
    prestataires: PersonneOption[];
    clients: PersonneOption[];
    default_site_id: string | null;
    categories: { value: string; label: string }[];
    can_change_site: boolean;
    initial_beneficiaire_type?: string;
    initial_beneficiaire_id?: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dépenses', href: '/backoffice/depenses' },
    { title: 'Nouvelle dépense', href: '/backoffice/depenses/create' },
];

// ── Concerné ─────────────────────────────────────────────────────────────────
const concerneSelectionne = ref(props.initial_beneficiaire_type ?? '');

watch(concerneSelectionne, () => {
    form.depense_type_id = '';
    form.beneficiaire_id = '';
    vehiculeSelected.value = null;
    employeSelected.value = null;
    livreurSelected.value = null;
    proprietaireSelected.value = null;
    prestataireSelected.value = null;
    clientSelected.value = null;
});

const typesFiltres = computed<TypeOption[]>(() =>
    concerneSelectionne.value
        ? props.types.filter((t) => t.categorie === concerneSelectionne.value)
        : [],
);

const selectPt = {
    root: { class: 'max-sm:min-h-11' },
    label: { class: 'min-w-0 truncate max-sm:!text-base' },
    option: { class: 'max-sm:min-h-11' },
    optionLabel: {
        class: 'min-w-0 whitespace-normal [overflow-wrap:anywhere]',
    },
};
const selectOverlayStyle = { width: '100%', maxWidth: 'calc(100vw - 2rem)' };

// ── Formulaire ────────────────────────────────────────────────────────────────
const form = useForm({
    depense_type_id: '',
    beneficiaire_id: props.initial_beneficiaire_id ?? '',
    site_id: props.default_site_id ?? '',
    montant: '' as number | '',
    date_depense: new Date().toISOString().slice(0, 10),
    commentaire: '',
    statut: 'brouillon' as 'brouillon' | 'soumis',
});

const showDiscardDialog = ref(false);

function annulerSaisie() {
    if (form.processing) return;
    if (
        form.isDirty ||
        concerneSelectionne.value !== (props.initial_beneficiaire_type ?? '')
    ) {
        showDiscardDialog.value = true;
        return;
    }
    quitterSaisie();
}

function quitterSaisie() {
    if (!form.processing) router.visit('/backoffice/depenses');
}

const selectedType = computed<TypeOption | null>(
    () => typesFiltres.value.find((t) => t.id === form.depense_type_id) ?? null,
);

watch(
    () => form.depense_type_id,
    () => {
        form.beneficiaire_id = '';
        vehiculeSelected.value = null;
        employeSelected.value = null;
        livreurSelected.value = null;
        proprietaireSelected.value = null;
        prestataireSelected.value = null;
        clientSelected.value = null;
    },
);

// ── Véhicule — recherche via modale ──────────────────────────────────────────
const vehiculeSelected = ref<Vehicule | null>(null);
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

const employeSelected = ref<PersonneOption | null>(null);
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

const livreurSelected = ref<PersonneOption | null>(null);
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

const proprietaireSelected = ref<PersonneOption | null>(null);
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

const prestataireSelected = ref<PersonneOption | null>(null);
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
    props.initial_beneficiaire_type === 'client' &&
        props.initial_beneficiaire_id
        ? (props.clients.find(
              (client) => client.id === props.initial_beneficiaire_id,
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

const categorie = computed(
    () => selectedType.value?.categorie ?? concerneSelectionne.value ?? null,
);

const concerneLabel = computed(
    () =>
        props.categories.find((c) => c.value === concerneSelectionne.value)
            ?.label ?? '',
);

const beneficiaireLabel = computed<string | null>(() => {
    if (!form.beneficiaire_id) return null;
    const cat = categorie.value;
    if (cat === 'vehicule') return vehiculeSelected.value?.nom_vehicule ?? null;
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

// ── Montant formaté ───────────────────────────────────────────────────────────
const montantDisplay = ref('');

function handleMontantInput(e: Event) {
    const raw = (e.target as HTMLInputElement).value.replace(/\D/g, '');
    form.montant = raw ? parseInt(raw, 10) : '';
    montantDisplay.value = raw
        ? parseInt(raw, 10).toLocaleString('fr-FR', {
              maximumFractionDigits: 0,
          })
        : '';
}

// ── Popup de confirmation ──────────────────────────────────────────────────────
const showConfirmDialog = ref(false);
const toast = useToast();

function openConfirmDialog() {
    showConfirmDialog.value = true;
}

function onConfirm() {
    form.statut = 'soumis';
    form.post('/backoffice/depenses', {
        forceFormData: false,
        onSuccess: () => {
            showConfirmDialog.value = false;
            toast.add({
                severity: 'success',
                summary: 'Dépense soumise pour validation',
                life: 4000,
            });
        },
        onError: () => {
            showConfirmDialog.value = false;
        },
    });
}

function onCancel() {
    showConfirmDialog.value = false;
}

function submitBrouillon() {
    form.statut = 'brouillon';
    form.post('/backoffice/depenses', {
        forceFormData: false,
        onSuccess: () => {
            toast.add({
                severity: 'success',
                summary: 'Dépense enregistrée en brouillon',
                life: 4000,
            });
        },
    });
}
</script>

<template>
    <Head title="Nouvelle dépense" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <header
            data-testid="depense-create-header"
            class="fixed inset-x-0 top-0 z-20 grid grid-cols-[2.75rem_minmax(0,1fr)_2.75rem] items-center gap-2 border-b bg-background px-4 pt-[max(0.5rem,env(safe-area-inset-top))] pb-2 sm:hidden"
        >
            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="h-11 w-11 text-muted-foreground"
                aria-label="Annuler la saisie"
                :disabled="form.processing"
                @click="annulerSaisie"
            >
                <ArrowLeft class="size-5" />
            </Button>
            <h1 class="text-center text-base font-semibold">
                Nouvelle dépense
            </h1>
        </header>

        <div
            class="min-w-0 px-4 pt-[calc(77px+env(safe-area-inset-top))] pb-[calc(6rem+env(safe-area-inset-bottom))] sm:p-6"
        >
            <div class="mx-auto max-w-2xl min-w-0">
                <!-- Header -->
                <div class="mb-4 sm:mb-6">
                    <h1 class="hidden text-xl font-semibold sm:block">
                        Nouvelle dépense
                    </h1>
                    <p class="text-sm text-muted-foreground sm:mt-1">
                        Choisissez le concerné, puis le type de dépense.
                    </p>
                </div>

                <form
                    id="depense-form"
                    class="depense-form min-w-0 space-y-4"
                    @submit.prevent
                >
                    <!-- Concerné -->
                    <fieldset
                        class="min-w-0 space-y-3 rounded-xl border bg-card p-4"
                    >
                        <legend class="sr-only">Concerné</legend>
                        <h2
                            class="text-sm font-semibold sm:text-xs sm:tracking-wide sm:text-muted-foreground sm:uppercase"
                        >
                            Concerné
                        </h2>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                            <label
                                v-for="cat in categories"
                                :key="cat.value"
                                class="flex min-h-12 min-w-0 cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm transition-colors focus-within:ring-2 focus-within:ring-ring sm:min-h-0"
                                :class="
                                    concerneSelectionne === cat.value
                                        ? 'border-primary bg-primary/5 font-medium text-primary ring-1 ring-primary'
                                        : 'hover:bg-muted/40'
                                "
                            >
                                <input
                                    v-model="concerneSelectionne"
                                    type="radio"
                                    name="concerne"
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
                    </fieldset>

                    <!-- Type de dépense -->
                    <div class="space-y-3 rounded-xl border bg-card p-4">
                        <h2
                            class="text-sm font-semibold sm:text-xs sm:tracking-wide sm:text-muted-foreground sm:uppercase"
                        >
                            Type de dépense
                        </h2>
                        <div>
                            <Label
                                for="dep-type"
                                class="mb-1.5 block text-sm font-medium sm:text-xs"
                            >
                                Type <span class="text-destructive">*</span>
                            </Label>
                            <Select
                                input-id="dep-type"
                                v-model="form.depense_type_id"
                                :options="typesFiltres"
                                option-label="libelle"
                                option-value="id"
                                :disabled="!concerneSelectionne"
                                :placeholder="
                                    concerneSelectionne
                                        ? 'Sélectionner un type'
                                        : 'Sélectionnez un concerné'
                                "
                                :invalid="!!form.errors.depense_type_id"
                                class="w-full min-w-0"
                                append-to="self"
                                :overlay-style="selectOverlayStyle"
                                :pt="selectPt"
                                scroll-height="min(15rem, 50dvh)"
                                empty-message="Aucun type disponible pour ce concerné"
                            />
                            <p
                                v-if="form.errors.depense_type_id"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.depense_type_id }}
                            </p>
                            <p
                                v-if="
                                    concerneSelectionne &&
                                    typesFiltres.length === 0
                                "
                                class="mt-1 text-xs text-amber-600"
                            >
                                Aucun type actif pour ce concerné. Ajoutez-en
                                dans les paramètres.
                            </p>
                        </div>
                    </div>

                    <!-- Bénéficiaire conditionnel -->
                    <div
                        v-if="selectedType && categorie !== 'interne'"
                        class="space-y-3 rounded-xl border bg-card p-4"
                    >
                        <h2
                            class="text-sm font-semibold sm:text-xs sm:tracking-wide sm:text-muted-foreground sm:uppercase"
                        >
                            {{ concerneLabel }}
                        </h2>

                        <!-- Véhicule -->
                        <div v-if="categorie === 'vehicule'">
                            <Label
                                for="dep-vehicule"
                                class="mb-1.5 block text-sm font-medium sm:text-xs"
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
                                        vehiculeSelected ? 'pr-12 sm:pr-8' : '',
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
                                    class="absolute top-1/2 right-0 flex h-11 w-11 -translate-y-1/2 items-center justify-center text-muted-foreground hover:text-foreground sm:right-2 sm:h-auto sm:w-auto"
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
                                        v-if="option.categorie === 'interne'"
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
                                class="mb-1.5 block text-sm font-medium sm:text-xs"
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
                                        employeSelected ? 'pr-12 sm:pr-8' : '',
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
                                    class="absolute top-1/2 right-0 flex h-11 w-11 -translate-y-1/2 items-center justify-center text-muted-foreground hover:text-foreground sm:right-2 sm:h-auto sm:w-auto"
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
                                            option.site_nom || option.telephone
                                        "
                                        class="mt-0.5 text-xs text-muted-foreground"
                                    >
                                        {{
                                            [option.site_nom, option.telephone]
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
                                class="mb-1.5 block text-sm font-medium sm:text-xs"
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
                                        livreurSelected ? 'pr-12 sm:pr-8' : '',
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
                                    class="absolute top-1/2 right-0 flex h-11 w-11 -translate-y-1/2 items-center justify-center text-muted-foreground hover:text-foreground sm:right-2 sm:h-auto sm:w-auto"
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
                                class="mb-1.5 block text-sm font-medium sm:text-xs"
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
                                        proprietaireSelected
                                            ? 'pr-12 sm:pr-8'
                                            : '',
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
                                    class="absolute top-1/2 right-0 flex h-11 w-11 -translate-y-1/2 items-center justify-center text-muted-foreground hover:text-foreground sm:right-2 sm:h-auto sm:w-auto"
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
                                class="mb-1.5 block text-sm font-medium sm:text-xs"
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
                                        prestataireSelected
                                            ? 'pr-12 sm:pr-8'
                                            : '',
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
                                    class="absolute top-1/2 right-0 flex h-11 w-11 -translate-y-1/2 items-center justify-center text-muted-foreground hover:text-foreground sm:right-2 sm:h-auto sm:w-auto"
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
                                class="mb-1.5 block text-sm font-medium sm:text-xs"
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
                                        clientSelected ? 'pr-12 sm:pr-8' : '',
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
                                    class="absolute top-1/2 right-0 flex h-11 w-11 -translate-y-1/2 items-center justify-center text-muted-foreground hover:text-foreground sm:right-2 sm:h-auto sm:w-auto"
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
                            class="text-sm font-semibold sm:text-xs sm:tracking-wide sm:text-muted-foreground sm:uppercase"
                        >
                            Détails
                        </h2>

                        <div
                            class="grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-3 sm:gap-3"
                        >
                            <div>
                                <Label
                                    for="dep-montant"
                                    class="mb-1.5 block text-sm font-medium sm:text-xs"
                                >
                                    Montant (GNF)
                                    <span class="text-destructive">*</span>
                                </Label>
                                <input
                                    id="dep-montant"
                                    :value="montantDisplay"
                                    type="text"
                                    inputmode="numeric"
                                    placeholder="0"
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
                                    class="mb-1.5 block text-sm font-medium sm:text-xs"
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
                                    class="mb-1.5 flex items-center gap-1 text-sm font-medium sm:text-xs"
                                >
                                    Site
                                    <Lock
                                        v-if="!can_change_site"
                                        class="h-3 w-3 text-muted-foreground"
                                    />
                                </Label>
                                <Select
                                    input-id="dep-site"
                                    v-model="form.site_id"
                                    :options="[
                                        {
                                            id: '',
                                            nom: 'Aucun site spécifique',
                                        },
                                        ...sites,
                                    ]"
                                    option-label="nom"
                                    option-value="id"
                                    :disabled="!can_change_site"
                                    class="w-full min-w-0"
                                    append-to="self"
                                    :overlay-style="selectOverlayStyle"
                                    :pt="selectPt"
                                    scroll-height="min(15rem, 50dvh)"
                                />
                            </div>
                        </div>

                        <div>
                            <Label
                                for="dep-comment"
                                class="mb-1.5 block text-sm font-medium sm:text-xs"
                            >
                                Commentaire
                                <span
                                    v-if="selectedType.commentaire_obligatoire"
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
                    <div
                        data-testid="depense-create-actions"
                        class="depense-create-actions fixed inset-x-0 bottom-0 z-20 flex justify-between border-t bg-background/95 px-4 py-3 backdrop-blur-sm sm:static sm:border-0 sm:bg-transparent sm:px-0 sm:pt-1 sm:pb-0 sm:backdrop-blur-none"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="hidden sm:inline-flex"
                            :disabled="form.processing"
                            @click="annulerSaisie"
                        >
                            Annuler
                        </Button>
                        <div class="flex w-full gap-2 sm:w-auto">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                class="h-12 px-4 sm:h-8 sm:px-3"
                                :disabled="
                                    form.processing || !form.depense_type_id
                                "
                                @click="submitBrouillon"
                            >
                                <span
                                    v-if="
                                        form.processing &&
                                        form.statut === 'brouillon'
                                    "
                                    >Enregistrement…</span
                                >
                                <template v-else>
                                    <span class="sm:hidden">Brouillon</span>
                                    <span class="hidden sm:inline"
                                        >Enregistrer comme brouillon</span
                                    >
                                </template>
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                class="h-12 min-w-0 flex-1 px-4 sm:h-8 sm:flex-none sm:px-3"
                                :disabled="
                                    form.processing || !form.depense_type_id
                                "
                                @click="openConfirmDialog"
                            >
                                <span class="sm:hidden">Soumettre</span>
                                <span class="hidden sm:inline"
                                    >Soumettre pour validation</span
                                >
                            </Button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <Dialog v-model:open="showDiscardDialog">
            <DialogContent
                class="max-h-[90dvh] overflow-y-auto sm:max-w-md max-sm:[&>button]:flex max-sm:[&>button]:size-11 max-sm:[&>button]:items-center max-sm:[&>button]:justify-center"
            >
                <DialogHeader class="pr-10 text-left">
                    <DialogTitle>Quitter la saisie ?</DialogTitle>
                    <DialogDescription
                        >Votre dépense n'est pas enregistrée. Si vous quittez,
                        les informations saisies seront
                        perdues.</DialogDescription
                    >
                </DialogHeader>
                <DialogFooter class="flex-col sm:flex-row">
                    <Button
                        variant="outline"
                        class="h-11"
                        @click="showDiscardDialog = false"
                        >Continuer la saisie</Button
                    >
                    <Button
                        class="h-11"
                        :disabled="form.processing"
                        @click="quitterSaisie"
                        >Quitter sans enregistrer</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <DepenseConfirmDialog
            v-model:visible="showConfirmDialog"
            :processing="form.processing && form.statut === 'soumis'"
            :concerne-label="beneficiaireLabel"
            :type="selectedType"
            :vehicule-nom="vehiculeSelected?.nom_vehicule ?? null"
            :vehicule-immatriculation="
                vehiculeSelected?.immatriculation ?? null
            "
            :montant="form.montant"
            :site-nom="siteNom"
            :commentaire="form.commentaire"
            @confirm="onConfirm"
            @cancel="onCancel"
        />
    </AppLayout>
</template>

<style scoped>
@media (max-width: 639px) {
    .depense-form :deep(input:not([type='radio'])),
    .depense-form :deep(select),
    .depense-form :deep(button[id^='dep-']) {
        min-width: 0;
        min-height: 44px;
        font-size: 16px;
    }

    .depense-form :deep(textarea) {
        min-height: 96px;
        font-size: 16px;
    }

    .depense-create-actions {
        padding-bottom: max(0.75rem, env(safe-area-inset-bottom));
    }
}
</style>
