<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Link } from '@inertiajs/vue3';
import { Lock, Plus, Save, Upload, X } from 'lucide-vue-next';
import AutoComplete from 'primevue/autocomplete';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { computed, ref, watch } from 'vue';

interface Option {
    value: number | string;
    label: string;
    telephone?: string | null;
}

interface EquipeOption {
    value: number;
    label: string;
    proprietaire_id: number | null;
    proprietaire_label?: string | null;
    somme_taux: number;
    livreur_principal?: { nom_complet: string; telephone: string } | null;
}

interface TypeOption {
    value: string;
    label: string;
    capacite_defaut: number;
}

interface FormData {
    nom_vehicule: string;
    immatriculation: string;
    type_vehicule: string | null;
    capacite_packs: number | null;
    proprietaire_id: number | null;
    equipe_livraison_id: number | null;
    taux_commission_proprietaire: number | null;
    pris_en_charge_par_usine: boolean;
    photo: File | null;
    is_active: boolean;
}

const props = defineProps<{
    form: FormData;
    errors: Partial<Record<keyof FormData, string>>;
    processing: boolean;
    proprietaires: Option[];
    equipes: EquipeOption[];
    types: TypeOption[];
    photoUrl?: string | null;
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

function onTypeChange(value: string) {
    const type = props.types.find((t) => t.value === value);
    emit('update:form', {
        ...props.form,
        type_vehicule: value,
        capacite_packs: type ? type.capacite_defaut : props.form.capacite_packs,
    });
}

function onEquipeChange(value: number | null) {
    const equipe = props.equipes.find((item) => item.value === value);
    emit('update:form', {
        ...props.form,
        equipe_livraison_id: value,
        proprietaire_id: equipe?.proprietaire_id ?? null,
        nom_vehicule: equipe ? equipe.label : props.form.nom_vehicule,
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

const selectedType = computed(() =>
    props.types.find((t) => t.value === props.form.type_vehicule),
);

const selectedEquipe = computed(() =>
    props.equipes.find((e) => e.value === props.form.equipe_livraison_id),
);

const selectedProprietaireLabel = computed(() => {
    if (selectedEquipe.value?.proprietaire_label) {
        return selectedEquipe.value.proprietaire_label;
    }
    const proprietaire = props.proprietaires.find(
        (p) => p.value === props.form.proprietaire_id,
    );
    return proprietaire?.label ?? '';
});

// ── AutoComplete : Équipe ────────────────────────────────────────────────────
const equipeSelected = ref<EquipeOption | null>(
    props.equipes.find((e) => e.value === props.form.equipe_livraison_id) ??
        null,
);
const equipeSuggests = ref<EquipeOption[]>([]);

function searchEquipe(event: { query: string }) {
    const q = event.query.toLowerCase().trim();
    equipeSuggests.value = q
        ? props.equipes.filter((e) => {
              if (e.label.toLowerCase().includes(q)) return true;
              const lp = e.livreur_principal;
              return lp
                  ? lp.nom_complet.toLowerCase().includes(q) ||
                        lp.telephone.includes(q)
                  : false;
          })
        : [...props.equipes];
}

function onEquipeACSelect(
    payload: { value: EquipeOption } | EquipeOption | null,
) {
    if (!payload) {
        onEquipeChange(null);
        return;
    }
    const selected = 'label' in payload ? payload : payload.value;
    onEquipeChange(selected?.value ?? null);
}

function onEquipeACClear() {
    equipeSelected.value = null;
    onEquipeChange(null);
}

// Taux propriétaire = 100 - somme taux membres équipe (lecture seule)
const tauxProprietaire = computed(() => {
    if (!selectedEquipe.value) return null;
    return Math.max(0, 100 - selectedEquipe.value.somme_taux);
});

const canSubmit = computed(
    () =>
        !props.processing &&
        !!props.form.equipe_livraison_id &&
        props.form.nom_vehicule.trim().length > 0 &&
        props.form.immatriculation.trim().length > 0 &&
        !!props.form.type_vehicule,
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
        <!-- Affectation (toujours en premier) -->
        <div class="order-1 rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3
                class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"
            >
                Affectation
            </h3>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <Label for="equipe_livraison_id" class="mb-1.5 block">
                        Équipe de livraison
                        <span class="text-destructive">*</span>
                    </Label>
                    <AutoComplete
                        v-model="equipeSelected"
                        input-id="equipe_livraison_id"
                        :suggestions="equipeSuggests"
                        option-label="label"
                        @complete="searchEquipe"
                        @item-select="onEquipeACSelect"
                        @clear="onEquipeACClear"
                        placeholder="Équipe, livreur ou téléphone…"
                        class="w-full"
                        input-class="w-full"
                        :class="{ 'p-invalid': errors.equipe_livraison_id }"
                        dropdown
                        force-selection
                    >
                        <template #option="{ option }">
                            <div class="py-0.5">
                                <div class="leading-tight font-medium">
                                    {{ option.label }}
                                </div>
                                <div
                                    v-if="option.livreur_principal"
                                    class="mt-0.5 flex items-center gap-1.5 text-xs text-muted-foreground"
                                >
                                    <span>{{
                                        option.livreur_principal.nom_complet
                                    }}</span>
                                    <span class="font-mono">
                                        ·
                                        {{ option.livreur_principal.telephone }}
                                    </span>
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
                                    href="/equipes-livraison/create"
                                    class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-primary transition-colors hover:bg-primary/10"
                                >
                                    <Plus class="h-3 w-3" /> Créer
                                </Link>
                            </div>
                        </template>
                    </AutoComplete>
                    <p
                        v-if="errors.equipe_livraison_id"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.equipe_livraison_id }}
                    </p>
                    <p
                        v-if="selectedEquipe"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        Σ taux livreurs :
                        <span class="font-semibold"
                            >{{ selectedEquipe.somme_taux }}%</span
                        >
                    </p>
                </div>

                <div>
                    <Label for="proprietaire_id" class="mb-1.5 block">
                        Propriétaire
                    </Label>
                    <div class="relative">
                        <InputText
                            id="proprietaire_id"
                            :model-value="selectedProprietaireLabel"
                            readonly
                            class="w-full cursor-not-allowed bg-muted/60 pr-10 text-muted-foreground"
                            :class="{ 'p-invalid': errors.proprietaire_id }"
                            placeholder="Sélectionnez une équipe"
                        />
                        <Lock
                            class="pointer-events-none absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 text-muted-foreground/80"
                        />
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Géré automatiquement depuis l'équipe.
                    </p>
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
        <div class="order-2 rounded-xl border bg-card p-4 shadow-sm sm:p-6">
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
                    <div class="relative">
                        <InputText
                            id="nom_vehicule"
                            :model-value="form.nom_vehicule"
                            @update:model-value="
                                $emit('update:form', {
                                    ...form,
                                    nom_vehicule: String($event ?? ''),
                                })
                            "
                            :readonly="Boolean(selectedEquipe)"
                            class="w-full"
                            :class="[
                                { 'p-invalid': errors.nom_vehicule },
                                selectedEquipe
                                    ? 'cursor-not-allowed bg-muted/60 pr-10 text-muted-foreground'
                                    : '',
                            ]"
                        />
                        <Lock
                            v-if="selectedEquipe"
                            class="pointer-events-none absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 text-muted-foreground/80"
                        />
                    </div>
                    <p
                        v-if="selectedEquipe"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        Nom du véhicule = Nom de l'équipe.
                    </p>
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
                        :model-value="form.type_vehicule"
                        @update:model-value="onTypeChange($event)"
                        :options="types"
                        option-label="label"
                        option-value="value"
                        placeholder="Sélectionner…"
                        class="w-full"
                        :class="{ 'p-invalid': errors.type_vehicule }"
                    />
                    <p
                        v-if="errors.type_vehicule"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.type_vehicule }}
                    </p>
                </div>

                <div>
                    <Label for="capacite_packs" class="mb-1.5 block">
                        Capacité (packs)
                        <span
                            v-if="selectedType"
                            class="ml-1 text-xs text-muted-foreground"
                        >
                            défaut : {{ selectedType.capacite_defaut }}
                        </span>
                    </Label>
                    <InputNumber
                        id="capacite_packs"
                        :model-value="form.capacite_packs"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                capacite_packs: $event,
                            })
                        "
                        :min="1"
                        :max="99999"
                        :use-grouping="false"
                        class="w-full"
                    />
                </div>
            </div>
        </div>

        <!-- Commission & Charges -->
        <div class="order-3 rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3
                class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"
            >
                Commission & Charges
            </h3>
            <div class="grid gap-5 sm:grid-cols-2">
                <div class="flex items-start gap-3">
                    <Checkbox
                        id="pris_en_charge_par_usine"
                        :model-value="Boolean(form.pris_en_charge_par_usine)"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                pris_en_charge_par_usine: $event === true,
                            })
                        "
                    />
                    <div>
                        <Label
                            for="pris_en_charge_par_usine"
                            class="cursor-pointer font-medium"
                        >
                            Pris en charge par l'usine
                        </Label>
                        <p class="text-xs text-muted-foreground">
                            Les frais du véhicule sont supportés par
                            l'organisation
                        </p>
                    </div>
                </div>

                <div>
                    <Label class="mb-1.5 block">Taux propriétaire (%)</Label>
                    <div
                        class="flex h-10 cursor-not-allowed items-center rounded-md border border-input bg-muted/60 px-3 text-sm"
                    >
                        <Lock
                            class="mr-2 h-3.5 w-3.5 shrink-0 text-muted-foreground/80"
                        />
                        <span v-if="selectedEquipe" class="font-medium">
                            {{ tauxProprietaire }} %
                        </span>
                        <span v-else class="text-muted-foreground/60">
                            — sélectionnez une équipe
                        </span>
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Calculé automatiquement depuis l'équipe.
                    </p>
                </div>
            </div>
        </div>

        <!-- Photo -->
        <div class="order-4 rounded-xl border bg-card p-4 shadow-sm sm:p-6">
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
        <div class="order-5 rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3
                class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"
            >
                Statut
            </h3>
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
                    <Label for="is_active" class="cursor-pointer font-medium">
                        Véhicule actif
                    </Label>
                    <p class="text-xs text-muted-foreground">
                        Décochez pour retirer le véhicule de la flotte active
                    </p>
                </div>
            </div>
        </div>

        <!-- Pied de page -->
        <div class="order-6 hidden items-center justify-between sm:flex">
            <a href="/vehicules">
                <Button type="button" variant="outline">Retour</Button>
            </a>
            <Button type="submit" :disabled="!canSubmit">
                <Save class="mr-2 h-4 w-4" />
                {{ processing ? 'Enregistrement…' : 'Enregistrer' }}
            </Button>
        </div>
        <div class="order-7 h-20 sm:hidden" />
    </form>
</template>
