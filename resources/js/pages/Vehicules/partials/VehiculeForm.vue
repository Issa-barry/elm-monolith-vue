<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Link } from '@inertiajs/vue3';
import { Building2, Save, Upload, X } from 'lucide-vue-next';
import AutoComplete from 'primevue/autocomplete';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { computed, ref, watch } from 'vue';
import CapacitesEditor, {
    type CapaciteRow,
    type CategorieOption,
} from './CapacitesEditor.vue';

interface Option {
    value: number | string;
    label: string;
    telephone?: string | null;
}

interface TypeOption {
    value: string;
    label: string;
}

interface SiteOption {
    id: string;
    nom: string;
}

/**
 * Propriété du véhicule (interne/partenaire) — sans rapport avec les Catégorie du catalogue
 * produit utilisées comme référence de capacité (prop categoriesProduit ci-dessous). Nom
 * distinct délibéré pour éviter toute confusion entre les deux notions.
 */
interface CategorieVehiculeOption {
    value: string;
    label: string;
}

interface FormData {
    nom_vehicule: string;
    immatriculation: string;
    type_vehicule_id: string | null;
    site_id: string | null;
    proprietaire_id: number | string | null;
    categorie: string | null;
    livraison_vente: boolean;
    livraison_logistique: boolean;
    photo: File | null;
    is_active: boolean;
    capacites: CapaciteRow[];
    seuil_dette_derogation: number | null;
}

const props = defineProps<{
    form: FormData;
    errors: Partial<Record<keyof FormData, string>>;
    processing: boolean;
    proprietaires: Option[];
    types: TypeOption[];
    categoriesVehicule: CategorieVehiculeOption[];
    categoriesProduit: CategorieOption[];
    photoUrl?: string | null;
    sites: SiteOption[];
    canChangeSite: boolean;
    showStatusField?: boolean;
    defaultProprietaireId?: number | string | null;
    /** Seuil global de dette (Paramètres > Ventes), affiché à titre indicatif — cf. section
     * "Contrôle des impayés" ci-dessous. */
    seuilGlobalImpayes?: number;
}>();

const emit = defineEmits<{ submit: []; 'update:form': [FormData] }>();

const photoPreview = ref<string | null>(props.photoUrl ?? null);
const fileInput = ref<HTMLInputElement | null>(null);

watch(
    () => props.photoUrl,
    (url) => {
        if (!props.form.photo) photoPreview.value = url ?? null;
    },
);

const seuilGlobalImpayesLabel = computed(() => {
    if (props.seuilGlobalImpayes === undefined) {
        return null;
    }

    return `${new Intl.NumberFormat('fr-FR').format(props.seuilGlobalImpayes)} GNF`;
});

function onTypeChange(value: string) {
    emit('update:form', {
        ...props.form,
        type_vehicule_id: value,
    });
}

function onPhotoChange(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    if (file) {
        photoPreview.value = URL.createObjectURL(file);
        emit('update:form', { ...props.form, photo: file });
    }
}

function removePhoto() {
    photoPreview.value = null;
    emit('update:form', { ...props.form, photo: null });
    if (fileInput.value) fileInput.value.value = '';
}

const currentSiteName = computed(
    () => props.sites.find((s) => s.id === props.form.site_id)?.nom ?? '—',
);

// ── AutoComplete : Propriétaire — toujours facultatif, pré-rempli par défaut
// (propriétaire de l'organisation) tant qu'aucun tiers n'est explicitement choisi. ──
const proprietaireSelected = ref<Option | null>(
    props.proprietaires.find((p) => p.value === props.form.proprietaire_id) ??
        null,
);
const proprietaireSuggests = ref<Option[]>([]);

watch(
    () => props.form.proprietaire_id,
    (id) => {
        proprietaireSelected.value =
            props.proprietaires.find((p) => p.value === id) ?? null;
    },
);

function searchProprietaire(event: { query: string }) {
    const q = event.query.toLowerCase().trim();
    proprietaireSuggests.value = q
        ? props.proprietaires.filter(
              (p) =>
                  p.label.toLowerCase().includes(q) ||
                  (p.telephone && p.telephone.includes(q)),
          )
        : [...props.proprietaires];
}

function onProprietaireSelect(p: Option | null) {
    emit('update:form', {
        ...props.form,
        proprietaire_id: p ? p.value : null,
    });
}

function onProprietaireClear() {
    proprietaireSelected.value = null;
    emit('update:form', {
        ...props.form,
        proprietaire_id: props.defaultProprietaireId ?? null,
    });
}

function onUsageChange(
    field: 'livraison_vente' | 'livraison_logistique',
    value: boolean,
) {
    emit('update:form', { ...props.form, [field]: value });
}

const auMoinsUnUsage = computed(
    () => props.form.livraison_vente || props.form.livraison_logistique,
);

const canSubmit = computed(
    () =>
        !props.processing &&
        !!props.form.site_id &&
        props.form.nom_vehicule.trim().length > 0 &&
        props.form.immatriculation.trim().length > 0 &&
        !!props.form.type_vehicule_id &&
        !!props.form.categorie &&
        auMoinsUnUsage.value,
);

function handleSubmit() {
    if (!canSubmit.value) return;
    emit('submit');
}
</script>

<template>
    <form
        id="vehicule-form"
        class="flex flex-col gap-4 sm:gap-6"
        @submit.prevent="handleSubmit"
    >
        <!-- Usages du véhicule -->
        <div class="order-1 rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3
                class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"
            >
                Usages du véhicule
                <span class="text-destructive">*</span>
            </h3>

            <div class="flex flex-col gap-3">
                <label class="flex cursor-pointer items-center gap-3">
                    <Checkbox
                        :model-value="form.livraison_vente"
                        @update:model-value="
                            onUsageChange('livraison_vente', $event === true)
                        "
                    />
                    <div>
                        <span class="text-sm font-medium">Livraison vente</span>
                        <p class="text-xs text-muted-foreground">
                            Sélectionnable pour une commande de vente ou au PDV.
                        </p>
                    </div>
                </label>

                <label class="flex cursor-pointer items-center gap-3">
                    <Checkbox
                        :model-value="form.livraison_logistique"
                        @update:model-value="
                            onUsageChange(
                                'livraison_logistique',
                                $event === true,
                            )
                        "
                    />
                    <div>
                        <span class="text-sm font-medium"
                            >Logistique / transfert</span
                        >
                        <p class="text-xs text-muted-foreground">
                            Sélectionnable pour un transfert entre sites.
                        </p>
                    </div>
                </label>
            </div>

            <p
                v-if="errors.livraison_vente"
                class="mt-2 text-xs text-destructive"
            >
                {{ errors.livraison_vente }}
            </p>
            <p
                v-else-if="!auMoinsUnUsage"
                class="mt-2 text-xs text-muted-foreground"
            >
                Cochez au moins un usage.
            </p>
        </div>

        <!-- Site & Propriétaire -->
        <div class="order-2 rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3
                class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"
            >
                Rattachement
            </h3>
            <div class="grid gap-5 sm:grid-cols-2">
                <!-- Site : tout véhicule est rattaché à un site. Verrouillé pour
                     un non-admin (son propre site) ; un admin peut choisir
                     n'importe quel site de l'organisation. -->
                <div>
                    <Label for="site_id" class="mb-1.5 flex items-center gap-1">
                        Site
                        <span class="text-destructive">*</span>
                    </Label>
                    <template v-if="canChangeSite">
                        <Dropdown
                            input-id="site_id"
                            :model-value="form.site_id"
                            @update:model-value="
                                $emit('update:form', {
                                    ...form,
                                    site_id: $event,
                                })
                            "
                            :options="sites"
                            option-label="nom"
                            option-value="id"
                            placeholder="Sélectionner…"
                            class="w-full"
                            :class="{ 'p-invalid': errors.site_id }"
                        />
                        <p
                            v-if="errors.site_id"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ errors.site_id }}
                        </p>
                    </template>
                    <template v-else>
                        <div
                            class="flex h-10 items-center gap-2 rounded-md border border-input bg-muted/60 px-3 text-sm text-muted-foreground"
                        >
                            <Building2 class="h-4 w-4 shrink-0" />
                            <span class="truncate">{{ currentSiteName }}</span>
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Votre site (non modifiable).
                        </p>
                    </template>
                </div>

                <!-- Catégorie : propriété du véhicule, indépendante de l'usage
                     vente/logistique ci-dessus — INTERNE (organisation) ou
                     PARTENAIRE (tiers réel, mais géré comme tout véhicule de
                     flotte). Détermine si un propriétaire tiers est requis. -->
                <div>
                    <Label
                        for="categorie"
                        class="mb-1.5 flex items-center gap-1"
                    >
                        Catégorie
                        <span class="text-destructive">*</span>
                    </Label>
                    <Dropdown
                        input-id="categorie"
                        :model-value="form.categorie"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                categorie: $event,
                            })
                        "
                        :options="categoriesVehicule"
                        option-label="label"
                        option-value="value"
                        placeholder="Sélectionner…"
                        class="w-full"
                        :class="{ 'p-invalid': errors.categorie }"
                    />
                    <p
                        v-if="errors.categorie"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.categorie }}
                    </p>
                    <p v-else class="mt-1 text-xs text-muted-foreground">
                        Partenaire = propriétaire tiers obligatoire ci-contre.
                    </p>
                </div>

                <!-- Propriétaire : toujours facultatif — un propriétaire par
                     défaut (organisation) s'applique si laissé vide. -->
                <div>
                    <Label class="mb-1.5 block">Propriétaire</Label>

                    <AutoComplete
                        v-model="proprietaireSelected"
                        input-id="proprietaire_id"
                        :suggestions="proprietaireSuggests"
                        option-label="label"
                        @complete="searchProprietaire"
                        @item-select="
                            onProprietaireSelect(proprietaireSelected)
                        "
                        @clear="onProprietaireClear"
                        placeholder="Nom ou téléphone… (par défaut : organisation)"
                        class="w-full"
                        input-class="w-full"
                        :class="{
                            'p-invalid': errors.proprietaire_id,
                        }"
                        dropdown
                        force-selection
                    >
                        <template #option="{ option }">
                            <div class="py-0.5">
                                <div class="leading-tight font-medium">
                                    {{ option.label }}
                                </div>
                                <div
                                    v-if="option.telephone"
                                    class="mt-0.5 font-mono text-xs text-muted-foreground"
                                >
                                    {{ option.telephone }}
                                </div>
                            </div>
                        </template>
                        <template #empty>
                            <div
                                class="flex items-center justify-between gap-2 px-1 py-0.5"
                            >
                                <span class="text-sm text-muted-foreground">
                                    Aucun résultat
                                </span>
                                <Link
                                    href="/backoffice/proprietaires/create"
                                    class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-primary transition-colors hover:bg-primary/10"
                                >
                                    + Créer
                                </Link>
                            </div>
                        </template>
                    </AutoComplete>
                    <p
                        v-if="errors.proprietaire_id"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.proprietaire_id }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Identification -->
        <div class="order-3 rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3
                class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"
            >
                Identification
            </h3>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <Label for="nom_vehicule" class="mb-1.5 block">
                        Nom du véhicule
                        <span class="text-destructive">*</span>
                    </Label>
                    <InputText
                        id="nom_vehicule"
                        :model-value="form.nom_vehicule"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                nom_vehicule: String($event ?? ''),
                            })
                        "
                        class="w-full"
                        :class="{ 'p-invalid': errors.nom_vehicule }"
                    />
                    <p
                        v-if="errors.nom_vehicule"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.nom_vehicule }}
                    </p>
                </div>

                <div>
                    <Label for="immatriculation" class="mb-1.5 block">
                        Immatriculation
                        <span class="text-destructive">*</span>
                    </Label>
                    <InputText
                        id="immatriculation"
                        :model-value="form.immatriculation"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                immatriculation: String($event).toUpperCase(),
                            })
                        "
                        class="w-full font-mono uppercase"
                        :class="{ 'p-invalid': errors.immatriculation }"
                        placeholder="EX-123-GN"
                    />
                    <p
                        v-if="errors.immatriculation"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.immatriculation }}
                    </p>
                </div>

                <div>
                    <Label for="type_vehicule" class="mb-1.5 block">
                        Type <span class="text-destructive">*</span>
                    </Label>
                    <Dropdown
                        input-id="type_vehicule"
                        :model-value="form.type_vehicule_id"
                        @update:model-value="onTypeChange($event)"
                        :options="types"
                        option-label="label"
                        option-value="value"
                        placeholder="Sélectionner…"
                        class="w-full"
                        :class="{ 'p-invalid': errors.type_vehicule_id }"
                    />
                    <p
                        v-if="errors.type_vehicule_id"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.type_vehicule_id }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Capacités de chargement -->
        <div class="order-4">
            <CapacitesEditor
                :model-value="form.capacites"
                :categories-produit="categoriesProduit"
                :errors="errors as Record<string, string>"
                @update:model-value="
                    $emit('update:form', { ...form, capacites: $event })
                "
            />
        </div>

        <!-- Contrôle des impayés : dérogation propre à ce véhicule -->
        <div class="order-5 rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3
                class="mb-1 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
            >
                Contrôle des impayés
            </h3>
            <p class="mb-4 text-xs text-muted-foreground">
                Facultatif. Laisser vide pour utiliser le seuil global des
                ventes<span v-if="seuilGlobalImpayesLabel"
                    >— seuil global actuel : {{ seuilGlobalImpayesLabel }}</span
                >. Ce n'est pas la dette actuelle du véhicule, mais la limite de
                dette autorisée avant blocage des nouvelles ventes.
            </p>
            <div class="max-w-xs">
                <Label for="seuil_dette_derogation" class="mb-1.5 block text-sm"
                    >Seuil de dette spécifique (GNF)</Label
                >
                <InputNumber
                    input-id="seuil_dette_derogation"
                    :model-value="form.seuil_dette_derogation"
                    @update:model-value="
                        $emit('update:form', {
                            ...form,
                            seuil_dette_derogation: $event,
                        })
                    "
                    :min="0"
                    :max="999999999"
                    :use-grouping="false"
                    placeholder="Seuil global"
                    class="w-full"
                    :class="{
                        'p-invalid': errors.seuil_dette_derogation,
                    }"
                />
                <p
                    v-if="errors.seuil_dette_derogation"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ errors.seuil_dette_derogation }}
                </p>
            </div>
        </div>

        <!-- Photo -->
        <div class="order-6 rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3
                class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"
            >
                Photo
            </h3>
            <div class="flex items-start gap-6">
                <div class="shrink-0">
                    <div
                        class="flex h-32 w-32 items-center justify-center overflow-hidden rounded-xl border bg-muted/30"
                    >
                        <img
                            v-if="photoPreview"
                            :src="photoPreview"
                            alt="Aperçu"
                            class="h-full w-full object-cover"
                        />
                        <span v-else class="text-3xl text-muted-foreground/40"
                            >🚗</span
                        >
                    </div>
                </div>
                <div class="flex flex-col gap-3">
                    <input
                        ref="fileInput"
                        type="file"
                        accept="image/jpg,image/jpeg,image/png,image/webp"
                        class="hidden"
                        @change="onPhotoChange"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="fileInput?.click()"
                    >
                        <Upload class="mr-2 h-4 w-4" />
                        {{ photoPreview ? 'Changer' : 'Ajouter une photo' }}
                    </Button>
                    <Button
                        v-if="photoPreview"
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="text-destructive hover:text-destructive"
                        @click="removePhoto"
                    >
                        <X class="mr-2 h-4 w-4" /> Supprimer
                    </Button>
                    <p class="text-xs text-muted-foreground">
                        JPG, PNG ou WebP — max 3 Mo
                    </p>
                    <p v-if="errors.photo" class="text-xs text-destructive">
                        {{ errors.photo }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Statut -->
        <div class="order-7 rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3
                class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"
            >
                Statut
            </h3>
            <template v-if="showStatusField">
                <div class="flex items-center gap-3">
                    <Checkbox
                        id="is_active"
                        :model-value="Boolean(form.is_active)"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                is_active: $event === true,
                            })
                        "
                    />
                    <div>
                        <Label
                            for="is_active"
                            class="cursor-pointer font-medium"
                        >
                            Véhicule actif
                        </Label>
                        <p class="text-xs text-muted-foreground">
                            Décochez pour retirer le véhicule de la flotte
                            active
                        </p>
                    </div>
                </div>
            </template>
            <template v-else>
                <p class="text-sm text-muted-foreground">
                    Le véhicule sera activé automatiquement lors de l'ajout
                    d'une équipe.
                </p>
            </template>
        </div>

        <!-- Pied de page -->
        <div class="order-8 hidden items-center justify-between sm:flex">
            <a href="/backoffice/vehicules">
                <Button type="button" variant="outline">Retour</Button>
            </a>
            <Button
                type="submit"
                data-testid="vehicle-form-submit"
                :disabled="!canSubmit"
            >
                <Save class="mr-2 h-4 w-4" />
                {{ processing ? 'Enregistrement…' : 'Enregistrer' }}
            </Button>
        </div>
        <div class="order-9 h-20 sm:hidden" />
    </form>
</template>
