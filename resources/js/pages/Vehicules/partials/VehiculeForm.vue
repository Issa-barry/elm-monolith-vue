<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Save, Upload, X } from 'lucide-vue-next';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { computed, ref, watch } from 'vue';

interface Option { value: number | string; label: string }
interface TypeOption { value: string; label: string; capacite_defaut: number }

interface FormData {
    nom_vehicule: string;
    immatriculation: string;
    type_vehicule: string | null;
    capacite_packs: number | null;
    proprietaire_id: number | null;
    livreur_principal_id: number | null;
    pris_en_charge_par_usine: boolean;
    taux_commission_livreur: number | null;
    taux_commission_proprietaire: number | null;
    photo: File | null;
    is_active: boolean;
}

const props = defineProps<{
    form: FormData;
    errors: Partial<Record<keyof FormData, string>>;
    processing: boolean;
    proprietaires: Option[];
    livreurs: Option[];
    types: TypeOption[];
    photoUrl?: string | null;
}>();

const emit = defineEmits<{ submit: []; 'update:form': [FormData] }>();

const photoPreview = ref<string | null>(props.photoUrl ?? null);
const fileInput = ref<HTMLInputElement | null>(null);

watch(() => props.photoUrl, (url) => {
    if (!props.form.photo) photoPreview.value = url ?? null;
});

function onTypeChange(value: string) {
    const type = props.types.find(t => t.value === value);
    emit('update:form', {
        ...props.form,
        type_vehicule: value,
        capacite_packs: type ? type.capacite_defaut : props.form.capacite_packs,
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

const selectedType = computed(() => props.types.find(t => t.value === props.form.type_vehicule));

// Taux propriétaire = 100 - taux livreur, calculé automatiquement
watch(
    () => props.form.taux_commission_livreur,
    (taux) => {
        const livreur = taux ?? 0;
        const proprietaire = Math.max(0, 100 - livreur);
        emit('update:form', { ...props.form, taux_commission_proprietaire: proprietaire });
    }
);
</script>

<template>
    <form id="vehicule-form" class="space-y-4 sm:space-y-6" @submit.prevent="emit('submit')">

        <!-- Identification -->
        <div class="rounded-xl border bg-card p-4 sm:p-6 shadow-sm">
            <h3 class="mb-4 sm:mb-5 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                Identification
            </h3>
            <div class="grid gap-5 sm:grid-cols-2">
                <!-- Nom -->
                <div>
                    <Label for="nom_vehicule" class="mb-1.5 block">Nom du véhicule <span class="text-destructive">*</span></Label>
                    <InputText
                        id="nom_vehicule"
                        :model-value="form.nom_vehicule"
                        @update:model-value="$emit('update:form', { ...form, nom_vehicule: String($event ?? '') })"
                        class="w-full"
                        :class="{ 'p-invalid': errors.nom_vehicule }"
                    />
                    <p v-if="errors.nom_vehicule" class="mt-1 text-xs text-destructive">{{ errors.nom_vehicule }}</p>
                </div>

                <!-- Immatriculation -->
                <div>
                    <Label for="immatriculation" class="mb-1.5 block">Immatriculation <span class="text-destructive">*</span></Label>
                    <InputText
                        id="immatriculation"
                        :model-value="form.immatriculation"
                        @update:model-value="$emit('update:form', { ...form, immatriculation: String($event).toUpperCase() })"
                        class="w-full font-mono uppercase"
                        :class="{ 'p-invalid': errors.immatriculation }"
                        placeholder="EX-123-GN"
                    />
                    <p v-if="errors.immatriculation" class="mt-1 text-xs text-destructive">{{ errors.immatriculation }}</p>
                </div>

                <!-- Type -->
                <div>
                    <Label class="mb-1.5 block">Type <span class="text-destructive">*</span></Label>
                    <Dropdown
                        :model-value="form.type_vehicule"
                        @update:model-value="onTypeChange($event)"
                        :options="types"
                        option-label="label"
                        option-value="value"
                        placeholder="Sélectionner…"
                        class="w-full"
                        :class="{ 'p-invalid': errors.type_vehicule }"
                    />
                    <p v-if="errors.type_vehicule" class="mt-1 text-xs text-destructive">{{ errors.type_vehicule }}</p>
                </div>

                <!-- Capacité -->
                <div>
                    <Label for="capacite_packs" class="mb-1.5 block">
                        Capacité (packs)
                        <span v-if="selectedType" class="ml-1 text-xs text-muted-foreground">défaut : {{ selectedType.capacite_defaut }}</span>
                    </Label>
                    <InputNumber
                        id="capacite_packs"
                        :model-value="form.capacite_packs"
                        @update:model-value="$emit('update:form', { ...form, capacite_packs: $event })"
                        :min="1"
                        :max="99999"
                        :use-grouping="false"
                        class="w-full"
                    />
                </div>
            </div>
        </div>

        <!-- Affectation -->
        <div class="rounded-xl border bg-card p-4 sm:p-6 shadow-sm">
            <h3 class="mb-4 sm:mb-5 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                Affectation
            </h3>
            <div class="grid gap-5 sm:grid-cols-2">
                <!-- Propriétaire -->
                <div>
                    <Label class="mb-1.5 block">Propriétaire <span class="text-destructive">*</span></Label>
                    <Dropdown
                        :model-value="form.proprietaire_id"
                        @update:model-value="$emit('update:form', { ...form, proprietaire_id: $event })"
                        :options="proprietaires"
                        option-label="label"
                        option-value="value"
                        placeholder="Sélectionner…"
                        class="w-full"
                        :class="{ 'p-invalid': errors.proprietaire_id }"
                    />
                    <p v-if="errors.proprietaire_id" class="mt-1 text-xs text-destructive">{{ errors.proprietaire_id }}</p>
                </div>

                <!-- Livreur principal -->
                <div>
                    <Label class="mb-1.5 block">Livreur principal</Label>
                    <Dropdown
                        :model-value="form.livreur_principal_id"
                        @update:model-value="$emit('update:form', { ...form, livreur_principal_id: $event })"
                        :options="livreurs"
                        option-label="label"
                        option-value="value"
                        placeholder="Aucun"
                        :show-clear="true"
                        class="w-full"
                    />
                </div>
            </div>
        </div>

        <!-- Commission -->
        <div class="rounded-xl border bg-card p-4 sm:p-6 shadow-sm">
            <h3 class="mb-4 sm:mb-5 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                Commission & Charges
            </h3>
            <div class="grid gap-5 sm:grid-cols-2">
                <div class="flex items-start gap-3 sm:col-span-2">
                    <Checkbox
                        id="pris_en_charge_par_usine"
                        :model-value="Boolean(form.pris_en_charge_par_usine)"
                        @update:model-value="$emit('update:form', { ...form, pris_en_charge_par_usine: $event === true })"
                    />
                    <div>
                        <Label for="pris_en_charge_par_usine" class="cursor-pointer font-medium">Pris en charge par l'usine</Label>
                        <p class="text-xs text-muted-foreground">Les frais du véhicule sont supportés par l'organisation</p>
                    </div>
                </div>

                <div>
                    <Label for="taux_commission_livreur" class="mb-1.5 block">Taux livreur (%)</Label>
                    <InputNumber
                        id="taux_commission_livreur"
                        :model-value="form.taux_commission_livreur"
                        @update:model-value="$emit('update:form', { ...form, taux_commission_livreur: $event })"
                        :min="0"
                        :max="100"
                        :max-fraction-digits="2"
                        suffix=" %"
                        class="w-full"
                    />
                </div>

                <div>
                    <Label for="taux_commission_proprietaire" class="mb-1.5 block">
                        Taux propriétaire (%)
                        <span class="ml-1 text-xs text-muted-foreground">— calculé automatiquement</span>
                    </Label>
                    <InputNumber
                        id="taux_commission_proprietaire"
                        :model-value="form.taux_commission_proprietaire"
                        :disabled="true"
                        :min="0"
                        :max="100"
                        :max-fraction-digits="2"
                        suffix=" %"
                        class="w-full opacity-70"
                    />
                </div>
            </div>
        </div>

        <!-- Photo -->
        <div class="rounded-xl border bg-card p-4 sm:p-6 shadow-sm">
            <h3 class="mb-4 sm:mb-5 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                Photo
            </h3>
            <div class="flex items-start gap-6">
                <!-- Aperçu -->
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
                        <span v-else class="text-3xl text-muted-foreground/40">🚗</span>
                    </div>
                </div>

                <!-- Contrôles -->
                <div class="flex flex-col gap-3">
                    <input
                        ref="fileInput"
                        type="file"
                        accept="image/jpg,image/jpeg,image/png,image/webp"
                        class="hidden"
                        @change="onPhotoChange"
                    />
                    <Button type="button" variant="outline" size="sm" @click="fileInput?.click()">
                        <Upload class="mr-2 h-4 w-4" />
                        {{ photoPreview ? 'Changer la photo' : 'Ajouter une photo' }}
                    </Button>
                    <Button
                        v-if="photoPreview"
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="text-destructive hover:text-destructive"
                        @click="removePhoto"
                    >
                        <X class="mr-2 h-4 w-4" />
                        Supprimer la photo
                    </Button>
                    <p class="text-xs text-muted-foreground">JPG, PNG ou WebP — max 3 Mo</p>
                    <p v-if="errors.photo" class="text-xs text-destructive">{{ errors.photo }}</p>
                </div>
            </div>
        </div>

        <!-- Statut -->
        <div class="rounded-xl border bg-card p-4 sm:p-6 shadow-sm">
            <h3 class="mb-4 sm:mb-5 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                Statut
            </h3>
            <div class="flex items-center gap-3">
                <Checkbox
                    id="is_active"
                    :model-value="Boolean(form.is_active)"
                    @update:model-value="$emit('update:form', { ...form, is_active: $event === true })"
                />
                <div>
                    <Label for="is_active" class="cursor-pointer font-medium">Véhicule actif</Label>
                    <p class="text-xs text-muted-foreground">Décochez pour retirer le véhicule de la flotte active</p>
                </div>
            </div>
        </div>

        <!-- Pied -->
        <div class="hidden items-center justify-between sm:flex">
            <a href="/vehicules">
                <Button type="button" variant="outline">
                    Retour
                </Button>
            </a>
            <Button type="submit" :disabled="processing">
                <Save class="mr-2 h-4 w-4" />
                {{ processing ? 'Enregistrement…' : 'Enregistrer' }}
            </Button>
        </div>
        <div class="h-20 sm:hidden" />
    </form>
</template>
