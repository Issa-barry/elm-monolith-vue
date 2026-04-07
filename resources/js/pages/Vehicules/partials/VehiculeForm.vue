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
    affectationFirst?: boolean;
    tauxProprietaireDefaut?: number;
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

// ── AutoComplete : Propriétaire ───────────────────────────────────────────────
const proprietaireSelected = ref<Option | null>(
    props.proprietaires.find((p) => p.value === props.form.proprietaire_id) ??
        null,
);
const proprietaireSuggests = ref<Option[]>([]);

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
        proprietaire_id: p ? (p.value as number) : null,
    });
}

function onProprietaireClear() {
    proprietaireSelected.value = null;
    emit('update:form', { ...props.form, proprietaire_id: null });
}

// ── AutoComplete : Équipe ─────────────────────────────────────────────────────
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

function onEquipeACSelect(e: EquipeOption | null) {
    onEquipeChange(e?.value ?? null);
}

function onEquipeACClear() {
    equipeSelected.value = null;
    onEquipeChange(null);
}

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
        const taux = equipe
            ? tauxRestantPourProprietaire.value
            : (props.tauxProprietaireDefaut ?? 100);
        emit('update:form', {
            ...props.form,
            nom_vehicule: equipe ? equipe.label : props.form.nom_vehicule,
            taux_commission_proprietaire: taux,
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
                        placeholder="Nom ou téléphone…"
                        class="w-full"
                        input-class="w-full"
                        :class="{ 'p-invalid': errors.proprietaire_id }"
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
                                <span class="text-sm text-muted-foreground"
                                    >Aucun résultat</span
                                >
                                <Link
                                    href="/proprietaires/create"
                                    class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-primary transition-colors hover:bg-primary/10"
                                >
                                    <Plus class="h-3 w-3" /> Créer
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

                <div>
                    <Label for="equipe_livraison_id" class="mb-1.5 block"
                        >Équipe de livraison</Label
                    >
                    <AutoComplete
                        v-model="equipeSelected"
                        input-id="equipe_livraison_id"
                        :suggestions="equipeSuggests"
                        option-label="label"
                        @complete="searchEquipe"
                        @item-select="onEquipeACSelect(equipeSelected)"
                        @clear="onEquipeACClear"
                        placeholder="Équipe, livreur ou téléphone…"
                        class="w-full"
                        input-class="w-full"
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
                                    <span class="font-mono"
                                        >·
                                        {{
                                            option.livreur_principal.telephone
                                        }}</span
                                    >
                                </div>
                            </div>
                        </template>
                        <template #empty>
                            <div
                                class="flex items-center justify-between gap-2 px-1 py-0.5"
                            >
                                <span class="text-sm text-muted-foreground"
                                    >Aucun résultat</span
                                >
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
