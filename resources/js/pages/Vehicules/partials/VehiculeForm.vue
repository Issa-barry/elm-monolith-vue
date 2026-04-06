<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Lock, Save, Upload, X } from 'lucide-vue-next';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { computed, ref, watch } from 'vue';

interface Option {
    value: number | string;
    label: string;
}

interface EquipeOption {
    value: number;
    label: string;
    somme_taux: number;
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
    affectationFirst?: boolean;
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
    emit('update:form', {
        ...props.form,
        equipe_livraison_id: value,
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

const isAffectationFirst = computed(() => props.affectationFirst === true);

// Taux restant pour le propriétaire = 100 - somme_taux_équipe
const tauxRestantPourProprietaire = computed(() => {
    if (!selectedEquipe.value) return 100;
    return Math.max(0, 100 - selectedEquipe.value.somme_taux);
});

// Quand l'équipe change, suggère automatiquement le taux propriétaire restant
watch(
    () => props.form.equipe_livraison_id,
    (equipeId) => {
        const equipe = props.equipes.find((e) => e.value === equipeId);
        emit('update:form', {
            ...props.form,
            nom_vehicule: equipe ? equipe.label : props.form.nom_vehicule,
            taux_commission_proprietaire: tauxRestantPourProprietaire.value,
        });
    },
);

const totalTaux = computed(() => {
    const equipe = selectedEquipe.value?.somme_taux ?? 0;
    const prop = props.form.taux_commission_proprietaire ?? 0;
    return Math.round((equipe + prop) * 100) / 100;
});
</script>

<template>
    <form
        id="vehicule-form"
        class="flex flex-col gap-4 sm:gap-6"
        @submit.prevent="emit('submit')"
    >
        <!-- Identification -->
        <div
            :class="[
                isAffectationFirst ? 'order-2' : 'order-1',
                'rounded-xl border bg-card p-4 shadow-sm sm:p-6',
            ]"
        >
            <h3
                class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"
            >
                Identification
            </h3>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <Label for="nom_vehicule" class="mb-1.5 block"
                        >Nom du v&eacute;hicule
                        <span class="text-destructive">*</span></Label
                    >
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
                        Nom de vehicule = Nom de l'equipe.
                    </p>
                    <p
                        v-if="errors.nom_vehicule"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.nom_vehicule }}
                    </p>
                </div>

                <div>
                    <Label for="immatriculation" class="mb-1.5 block"
                        >Immatriculation
                        <span class="text-destructive">*</span></Label
                    >
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
                    <Label for="type_vehicule" class="mb-1.5 block"
                        >Type <span class="text-destructive">*</span></Label
                    >
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
                            >défaut : {{ selectedType.capacite_defaut }}</span
                        >
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

        <!-- Affectation -->
        <div
            :class="[
                isAffectationFirst ? 'order-1' : 'order-2',
                'rounded-xl border bg-card p-4 shadow-sm sm:p-6',
            ]"
        >
            <h3
                class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"
            >
                Affectation
            </h3>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <Label for="proprietaire_id" class="mb-1.5 block"
                        >Propriétaire
                        <span class="text-destructive">*</span></Label
                    >
                    <Dropdown
                        input-id="proprietaire_id"
                        :model-value="form.proprietaire_id"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                proprietaire_id: $event,
                            })
                        "
                        :options="proprietaires"
                        option-label="label"
                        option-value="value"
                        placeholder="Sélectionner…"
                        class="w-full"
                        :class="{ 'p-invalid': errors.proprietaire_id }"
                    />
                    <p
                        v-if="errors.proprietaire_id"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.proprietaire_id }}
                    </p>
                </div>

                <div>
                    <Label for="equipe_livraison_id" class="mb-1.5 block"
                        >Équipe de livraison</Label
                    >
                    <Dropdown
                        input-id="equipe_livraison_id"
                        :model-value="form.equipe_livraison_id"
                        @update:model-value="onEquipeChange($event)"
                        :options="equipes"
                        option-label="label"
                        option-value="value"
                        placeholder="Aucune"
                        :show-clear="true"
                        class="w-full"
                    />
                    <p
                        v-if="selectedEquipe"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        Σ taux équipe :
                        <span class="font-semibold"
                            >{{ selectedEquipe.somme_taux }}%</span
                        >
                    </p>
                </div>
            </div>
        </div>

        <!-- Commission -->
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
                            >Pris en charge par l'usine</Label
                        >
                        <p class="text-xs text-muted-foreground">
                            Les frais du véhicule sont supportés par
                            l'organisation
                        </p>
                    </div>
                </div>

                <div>
                    <Label for="taux_proprietaire" class="mb-1.5 block">
                        Taux propriétaire (%)
                        <span
                            v-if="selectedEquipe"
                            class="ml-1 text-xs text-muted-foreground"
                            >— suggéré :
                            {{ tauxRestantPourProprietaire }}%</span
                        >
                    </Label>
                    <InputNumber
                        id="taux_proprietaire"
                        :model-value="form.taux_commission_proprietaire"
                        @update:model-value="
                            $emit('update:form', {
                                ...form,
                                taux_commission_proprietaire: $event,
                            })
                        "
                        :min="0"
                        :max="100"
                        :max-fraction-digits="2"
                        suffix=" %"
                        class="w-full"
                    />
                    <p
                        v-if="selectedEquipe"
                        class="mt-1 text-xs"
                        :class="
                            Math.abs(totalTaux - 100) > 0.01
                                ? 'text-destructive'
                                : 'text-emerald-600'
                        "
                    >
                        Total : {{ totalTaux }}%
                        {{
                            Math.abs(totalTaux - 100) > 0.01
                                ? '— doit être égal à 100 %'
                                : '✓'
                        }}
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
                    <Label for="is_active" class="cursor-pointer font-medium"
                        >Véhicule actif</Label
                    >
                    <p class="text-xs text-muted-foreground">
                        Décochez pour retirer le véhicule de la flotte active
                    </p>
                </div>
            </div>
        </div>

        <!-- Pied -->
        <div class="order-6 hidden items-center justify-between sm:flex">
            <a href="/vehicules">
                <Button type="button" variant="outline">Retour</Button>
            </a>
            <Button type="submit" :disabled="processing">
                <Save class="mr-2 h-4 w-4" />
                {{ processing ? 'Enregistrement…' : 'Enregistrer' }}
            </Button>
        </div>
        <div class="order-7 h-20 sm:hidden" />
    </form>
</template>
