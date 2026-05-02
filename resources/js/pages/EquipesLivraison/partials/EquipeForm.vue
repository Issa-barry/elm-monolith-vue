<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { formatPhoneDisplay } from '@/lib/utils';
import {
    AlertTriangle,
    Building2,
    ChevronRight,
    Lock,
    Pencil,
    Plus,
    Save,
    Trash2,
} from 'lucide-vue-next';
import AutoComplete from 'primevue/autocomplete';
import { computed, ref, watch } from 'vue';
import MembreModal, { type MembreFormData } from './MembreModal.vue';
import PartageModal, { type PartageResult } from './PartageModal.vue';

interface VehiculeOption {
    value: string;
    label: string;
    immatriculation: string;
    categorie: string;
    type_label: string;
    proprietaire_id: string | null;
    proprietaire_nom: string | null;
}

type Membre = MembreFormData;

interface ProprietaireOption {
    value: string;
    label: string;
    telephone?: string | null;
}

interface FormData {
    nom: string;
    is_active: boolean;
    vehicule_id: string | null;
    proprietaire_id: string | null;
    commission_unitaire_par_pack: number;
    montant_par_pack_proprietaire: number | null;
    membres: Membre[];
    errors?: Record<string, string>;
    processing?: boolean;
}

const props = defineProps<{
    form: FormData;
    proprietaires: ProprietaireOption[];
    vehicules: VehiculeOption[];
    currentSiteName: string;
}>();
const emit = defineEmits<{ submit: [] }>();

// ── AutoComplete : Véhicule ───────────────────────────────────────────────────

const vehiculeSelected = ref<VehiculeOption | null>(
    props.vehicules.find((v) => v.value === props.form.vehicule_id) ?? null,
);
const vehiculeSuggests = ref<VehiculeOption[]>([]);

function searchVehicule(event: { query: string }) {
    const q = event.query.toLowerCase().trim();
    vehiculeSuggests.value = q
        ? props.vehicules.filter(
              (v) =>
                  v.label.toLowerCase().includes(q) ||
                  v.immatriculation.toLowerCase().includes(q),
          )
        : [...props.vehicules];
}

function onVehiculeSelect(v: VehiculeOption | null) {
    // eslint-disable-next-line vue/no-mutating-props
    props.form.vehicule_id = v ? v.value : null;
    // eslint-disable-next-line vue/no-mutating-props
    props.form.nom = v ? v.label : '';
    if (v?.categorie === 'interne') {
        proprietaireSelected.value = null;
        // eslint-disable-next-line vue/no-mutating-props
        props.form.proprietaire_id = null;
        // eslint-disable-next-line vue/no-mutating-props
        props.form.montant_par_pack_proprietaire = null;
    } else if (v?.proprietaire_id) {
        const prop = props.proprietaires.find(
            (p) => p.value === v.proprietaire_id,
        );
        proprietaireSelected.value = prop ?? null;
        // eslint-disable-next-line vue/no-mutating-props
        props.form.proprietaire_id = v.proprietaire_id;
    } else {
        proprietaireSelected.value = null;
        // eslint-disable-next-line vue/no-mutating-props
        props.form.proprietaire_id = null;
    }
}

function onVehiculeClear() {
    vehiculeSelected.value = null;
    // eslint-disable-next-line vue/no-mutating-props
    props.form.vehicule_id = null;
    // eslint-disable-next-line vue/no-mutating-props
    props.form.nom = '';
    proprietaireSelected.value = null;
    // eslint-disable-next-line vue/no-mutating-props
    props.form.proprietaire_id = null;
    // eslint-disable-next-line vue/no-mutating-props
    props.form.montant_par_pack_proprietaire = null;
}

// ── AutoComplete : Propriétaire ───────────────────────────────────────────────

const proprietaireSelected = ref<ProprietaireOption | null>(
    props.proprietaires.find((p) => p.value === props.form.proprietaire_id) ??
        null,
);
const proprietaireSuggests = ref<ProprietaireOption[]>([]);

function _searchProprietaire(event: { query: string }) {
    const q = event.query.toLowerCase().trim();
    proprietaireSuggests.value = q
        ? props.proprietaires.filter(
              (p) =>
                  p.label.toLowerCase().includes(q) ||
                  (p.telephone && p.telephone.includes(q)),
          )
        : [...props.proprietaires];
}

function _onProprietaireSelect(p: ProprietaireOption | null) {
    // eslint-disable-next-line vue/no-mutating-props
    props.form.proprietaire_id = p ? p.value : null;
}

function _onProprietaireClear() {
    proprietaireSelected.value = null;
    // eslint-disable-next-line vue/no-mutating-props
    props.form.proprietaire_id = null;
}

// ── Computed ──────────────────────────────────────────────────────────────────

const vehiculeCourant = computed(
    () =>
        vehiculeSelected.value ??
        props.vehicules.find((v) => v.value === props.form.vehicule_id) ??
        null,
);

const vehiculeIsInterne = computed(
    () => vehiculeCourant.value?.categorie === 'interne',
);

const vehiculeHasProprietaire = computed(
    () => !!vehiculeCourant.value?.proprietaire_id,
);

const vehiculeWarning = computed(() => {
    if (!props.form.vehicule_id) return 'Le véhicule est obligatoire.';
    return null;
});

const proprietaireWarning = computed(() => {
    if (vehiculeIsInterne.value) return null;
    if (!props.form.proprietaire_id) return 'Le propriétaire est obligatoire.';
    return null;
});

watch(vehiculeIsInterne, (isInterne) => {
    if (!isInterne) return;
    proprietaireSelected.value = null;
    // eslint-disable-next-line vue/no-mutating-props
    props.form.proprietaire_id = null;
    // eslint-disable-next-line vue/no-mutating-props
    props.form.montant_par_pack_proprietaire = null;
});

const partageWarning = computed(() => {
    if (props.form.membres.length === 0) return null;
    if (!props.form.commission_unitaire_par_pack || props.form.commission_unitaire_par_pack <= 0) {
        return 'Configurez le partage (commission par pack non définie).';
    }
    const totalMembres = props.form.membres.reduce(
        (s, m) => s + (m.montant_par_pack || 0),
        0,
    );
    const totalProp = vehiculeIsInterne.value
        ? 0
        : (props.form.montant_par_pack_proprietaire ?? 0);
    const total = totalMembres + totalProp;
    if (Math.abs(total - props.form.commission_unitaire_par_pack) > 0.01) {
        return `Le partage doit totaliser ${props.form.commission_unitaire_par_pack} GNF. Actuellement : ${total} GNF.`;
    }
    return null;
});

const partageResume = computed(() => {
    const commission = props.form.commission_unitaire_par_pack;
    if (!commission || commission <= 0) return null;
    const totalMembres = props.form.membres.reduce(
        (s, m) => s + (m.montant_par_pack || 0),
        0,
    );
    const totalProp = vehiculeIsInterne.value
        ? 0
        : (props.form.montant_par_pack_proprietaire ?? 0);
    const isOk = Math.abs(totalMembres + totalProp - commission) < 0.01;
    return { commission, isOk };
});

// ── Modal membre ──────────────────────────────────────────────────────────────

const showModal = ref(false);
const editingIndex = ref<number | null>(null);

const membreEnEdition = computed<MembreFormData | null>(() =>
    editingIndex.value !== null ? props.form.membres[editingIndex.value] : null,
);

function openNewMembre() {
    editingIndex.value = null;
    showModal.value = true;
}

function openEditMembre(index: number) {
    editingIndex.value = index;
    showModal.value = true;
}

function onMembreConfirm(data: MembreFormData) {
    if (editingIndex.value === null) {
        // eslint-disable-next-line vue/no-mutating-props
        props.form.membres.push({ ...data, ordre: props.form.membres.length });
    } else {
        Object.assign(props.form.membres[editingIndex.value], data);
    }
    props.form.membres.forEach((m, i) => (m.ordre = i));
}

function removeMembre(index: number) {
    // eslint-disable-next-line vue/no-mutating-props
    props.form.membres.splice(index, 1);
    props.form.membres.forEach((m, i) => (m.ordre = i));
}

// ── Modal partage ─────────────────────────────────────────────────────────────

const showPartageModal = ref(false);

const partageProprietaireNom = computed(() =>
    vehiculeIsInterne.value
        ? null
        : (proprietaireSelected.value?.label ??
          props.proprietaires.find((p) => p.value === props.form.proprietaire_id)
              ?.label ??
          null),
);

const montantsInitiaux = computed(() => ({
    montant_proprietaire: props.form.montant_par_pack_proprietaire,
    montants_membres: props.form.membres.map((m) => m.montant_par_pack),
}));

function onPartageConfirm(result: PartageResult) {
    // eslint-disable-next-line vue/no-mutating-props
    props.form.commission_unitaire_par_pack = result.commission_unitaire_par_pack;
    // eslint-disable-next-line vue/no-mutating-props
    props.form.montant_par_pack_proprietaire = result.montant_par_pack_proprietaire;
    result.membres_montants.forEach((montant, i) => {
        if (props.form.membres[i]) {
            props.form.membres[i].montant_par_pack = montant;
        }
    });
}

// ── Affichage ─────────────────────────────────────────────────────────────────

function roleBadgeLabel(membres: Membre[], index: number): string {
    const role = membres[index].role;
    let count = 0;
    for (let i = 0; i <= index; i++) {
        if (membres[i].role === role) count++;
    }
    return `${role === 'chauffeur' ? 'Chauffeur' : 'Convoyeur'} ${count}`;
}

function initiales(prenom: string, nom: string): string {
    const p = prenom.trim()[0] ?? '';
    const n = nom.trim()[0] ?? '';
    return (p + n).toUpperCase();
}

function setIsActive(val: boolean | string) {
    // eslint-disable-next-line vue/no-mutating-props
    props.form.is_active = val === true;
}

// ── Submit ────────────────────────────────────────────────────────────────────

function handleSubmit() {
    if (vehiculeWarning.value || proprietaireWarning.value || partageWarning.value)
        return;
    emit('submit');
}
</script>

<template>
    <form class="space-y-4 sm:space-y-6" @submit.prevent="handleSubmit">
        <!-- Véhicule -->
        <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3
                class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
            >
                Véhicule
            </h3>
            <div class="space-y-4">
                <!-- Véhicule affecté -->
                <div>
                    <Label for="vehicule_id" class="mb-1.5 block">
                        Véhicule affecté
                        <span class="text-destructive">*</span>
                    </Label>
                    <AutoComplete
                        v-model="vehiculeSelected"
                        input-id="vehicule_id"
                        :suggestions="vehiculeSuggests"
                        option-label="label"
                        placeholder="Nom ou immatriculation…"
                        class="w-full"
                        input-class="w-full"
                        :class="{ 'p-invalid': form.errors?.vehicule_id }"
                        dropdown
                        force-selection
                        @complete="searchVehicule"
                        @item-select="onVehiculeSelect(vehiculeSelected)"
                        @clear="onVehiculeClear"
                    >
                        <template #option="{ option }">
                            <div class="py-0.5">
                                <div class="leading-tight font-medium">
                                    {{ option.label }}
                                </div>
                                <div
                                    class="mt-0.5 flex items-center gap-2 text-xs text-muted-foreground"
                                >
                                    <span class="font-mono">{{ option.immatriculation }}</span>
                                    <span>·</span>
                                    <span>{{ option.type_label }}</span>
                                    <span>·</span>
                                    <span class="capitalize">{{ option.categorie }}</span>
                                </div>
                            </div>
                        </template>
                        <template #empty>
                            <div class="px-1 py-0.5 text-sm text-muted-foreground">
                                Aucun véhicule disponible
                            </div>
                        </template>
                    </AutoComplete>
                    <p
                        v-if="form.errors?.vehicule_id"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ form.errors.vehicule_id }}
                    </p>
                    <div
                        v-if="vehiculeWarning && !form.errors?.vehicule_id"
                        class="mt-2 flex items-center gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-300"
                    >
                        <AlertTriangle class="h-3.5 w-3.5 shrink-0" />
                        {{ vehiculeWarning }}
                    </div>
                </div>

                <!-- Nom de l'équipe (auto-renseigné) -->
                <div>
                    <Label for="nom" class="mb-1.5 block">
                        Nom de l'équipe
                        <span class="text-destructive">*</span>
                    </Label>
                    <div
                        class="flex h-10 w-full items-center justify-between rounded-md border border-input bg-muted/40 px-3 py-2 text-sm"
                        :class="{ 'border-destructive': form.errors?.nom }"
                    >
                        <span :class="form.nom ? 'text-foreground' : 'text-muted-foreground'">
                            {{ form.nom || 'Sélectionnez un véhicule…' }}
                        </span>
                        <Lock class="h-3.5 w-3.5 shrink-0 text-muted-foreground/60" />
                    </div>
                    <p v-if="form.errors?.nom" class="mt-1 text-xs text-destructive">
                        {{ form.errors.nom }}
                    </p>
                </div>

                <!-- Propriétaire -->
                <div>
                    <Label for="proprietaire_id" class="mb-1.5 block">
                        Propriétaire
                        <span v-if="!vehiculeIsInterne" class="text-destructive">*</span>
                    </Label>

                    <!-- Interne : le propriétaire est le site -->
                    <div
                        v-if="vehiculeIsInterne"
                        class="flex h-10 w-full items-center justify-between rounded-md border border-input bg-muted/40 px-3 py-2 text-sm"
                    >
                        <span class="inline-flex items-center gap-2 text-foreground">
                            <Building2 class="h-3.5 w-3.5 text-muted-foreground/70" />
                            {{ currentSiteName }}
                        </span>
                        <Lock class="h-3.5 w-3.5 shrink-0 text-muted-foreground/60" />
                    </div>

                    <!-- Verrouillé : auto-renseigné depuis le véhicule -->
                    <div
                        v-else-if="vehiculeHasProprietaire"
                        class="flex h-10 w-full items-center justify-between rounded-md border border-input bg-muted/40 px-3 py-2 text-sm"
                        :class="{ 'border-destructive': form.errors?.proprietaire_id }"
                    >
                        <span class="text-foreground">{{ proprietaireSelected?.label }}</span>
                        <Lock class="h-3.5 w-3.5 shrink-0 text-muted-foreground/60" />
                    </div>

                    <!-- Éditable -->
                    <AutoComplete
                        v-else
                        v-model="proprietaireSelected"
                        input-id="proprietaire_id"
                        :suggestions="proprietaireSuggests"
                        option-label="label"
                        placeholder="Nom ou téléphone…"
                        class="w-full"
                        input-class="w-full"
                        :class="{ 'p-invalid': form.errors?.proprietaire_id }"
                        dropdown
                        force-selection
                        @complete="_searchProprietaire"
                        @item-select="_onProprietaireSelect(proprietaireSelected)"
                        @clear="_onProprietaireClear"
                    >
                        <template #option="{ option }">
                            <div class="py-0.5">
                                <div class="leading-tight font-medium">{{ option.label }}</div>
                                <div
                                    v-if="option.telephone"
                                    class="mt-0.5 font-mono text-xs text-muted-foreground"
                                >
                                    {{ option.telephone }}
                                </div>
                            </div>
                        </template>
                        <template #empty>
                            <div class="px-1 py-0.5 text-sm text-muted-foreground">Aucun résultat</div>
                        </template>
                    </AutoComplete>

                    <p
                        v-if="form.errors?.proprietaire_id"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ form.errors.proprietaire_id }}
                    </p>
                    <div
                        v-if="proprietaireWarning && !form.errors?.proprietaire_id"
                        class="mt-2 flex items-center gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-300"
                    >
                        <AlertTriangle class="h-3.5 w-3.5 shrink-0" />
                        {{ proprietaireWarning }}
                    </div>
                </div>
            </div>
        </div>

        <!-- Statut -->
        <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3
                class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
            >
                Statut
            </h3>
            <div class="flex items-center gap-3">
                <Checkbox
                    id="is_active"
                    :model-value="Boolean(form.is_active)"
                    @update:model-value="setIsActive($event)"
                />
                <div>
                    <Label for="is_active" class="cursor-pointer font-medium">Actif</Label>
                    <p class="text-xs text-muted-foreground">
                        Décochez pour désactiver l'équipe.
                    </p>
                </div>
            </div>
        </div>

        <!-- Membres -->
        <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <!-- En-tête section -->
            <div class="mb-4 flex items-start justify-between gap-4">
                <div>
                    <h3
                        class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Membres
                    </h3>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ form.membres.length }} membre(s)
                    </p>
                </div>
                <Button type="button" size="sm" @click="openNewMembre">
                    <Plus class="mr-1.5 h-3.5 w-3.5" />
                    Ajouter un membre
                </Button>
            </div>

            <p
                v-if="form.errors?.membres"
                class="mb-3 text-xs text-destructive"
            >
                {{ form.errors.membres }}
            </p>

            <!-- État vide -->
            <div
                v-if="form.membres.length === 0"
                class="rounded-lg border border-dashed py-10 text-center"
            >
                <p class="text-sm text-muted-foreground">Aucun membre.</p>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="mt-3"
                    @click="openNewMembre"
                >
                    <Plus class="mr-1.5 h-3.5 w-3.5" />
                    Ajouter le premier membre
                </Button>
            </div>

            <!-- Liste des membres -->
            <div v-else class="divide-y rounded-lg border">
                <div
                    v-for="(membre, index) in form.membres"
                    :key="index"
                    class="flex items-center gap-4 px-4 py-3 transition-colors hover:bg-muted/30"
                >
                    <!-- Avatar + identité -->
                    <div class="flex min-w-0 flex-1 items-center gap-3">
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                            :class="
                                membre.role === 'chauffeur'
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-muted text-muted-foreground'
                            "
                        >
                            {{ initiales(membre.prenom, membre.nom) }}
                        </div>
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium">
                                {{ membre.prenom }} {{ membre.nom }}
                            </div>
                            <div
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ formatPhoneDisplay(membre.telephone) }}
                            </div>
                            <p
                                v-if="
                                    form.errors?.[`membres.${index}.telephone`]
                                "
                                class="text-xs text-destructive"
                            >
                                {{ form.errors[`membres.${index}.telephone`] }}
                            </p>
                        </div>
                    </div>

                    <!-- Badge rôle -->
                    <div class="w-28 shrink-0 text-center">
                        <span
                            class="inline-block rounded-sm px-2 py-0.5 text-[10px] font-semibold tracking-wide uppercase"
                            :class="
                                membre.role === 'chauffeur'
                                    ? 'bg-primary/10 text-primary'
                                    : 'bg-muted text-muted-foreground'
                            "
                        >
                            {{ roleBadgeLabel(form.membres, index) }}
                        </span>
                    </div>

                    <!-- Actions -->
                    <div class="flex shrink-0 gap-0.5">
                        <button
                            type="button"
                            title="Modifier ce membre"
                            class="inline-flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                            @click="openEditMembre(index)"
                        >
                            <Pencil class="h-3.5 w-3.5" />
                        </button>
                        <button
                            type="button"
                            title="Supprimer ce membre"
                            class="inline-flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                            @click="removeMembre(index)"
                        >
                            <Trash2 class="h-3.5 w-3.5" />
                        </button>
                    </div>
                </div>
            </div>

            <!-- ── Bloc Partage ─────────────────────────────────────────── -->
            <div
                class="mt-4 rounded-lg border"
                :class="
                    partageWarning
                        ? 'border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-950'
                        : 'border-input bg-muted/20'
                "
            >
                <div class="flex items-center justify-between px-4 py-3">
                    <div>
                        <p
                            class="text-sm font-semibold"
                            :class="
                                partageWarning
                                    ? 'text-amber-800 dark:text-amber-300'
                                    : 'text-foreground'
                            "
                        >
                            Partage par pack
                        </p>
                        <p
                            v-if="partageResume && !partageWarning"
                            class="mt-0.5 text-xs text-emerald-600"
                        >
                            {{ partageResume.commission }} GNF/pack ·
                            répartition validée ✓
                        </p>
                        <p
                            v-else-if="partageWarning"
                            class="mt-0.5 text-xs text-amber-700 dark:text-amber-400"
                        >
                            {{ partageWarning }}
                        </p>
                        <p v-else class="mt-0.5 text-xs text-muted-foreground">
                            Définissez la commission et les montants par
                            bénéficiaire.
                        </p>
                    </div>
                    <Button
                        type="button"
                        :variant="partageWarning ? 'default' : 'outline'"
                        size="sm"
                        :disabled="form.membres.length === 0"
                        @click="showPartageModal = true"
                    >
                        <ChevronRight class="mr-1.5 h-3.5 w-3.5" />
                        Configurer le partage
                    </Button>
                </div>
            </div>
        </div>

        <!-- Pied de formulaire -->
        <div class="flex items-center justify-between">
            <a href="/equipes-livraison">
                <Button type="button" variant="outline">Retour</Button>
            </a>
            <Button
                type="submit"
                :disabled="
                    form.processing ||
                    !!vehiculeWarning ||
                    !!proprietaireWarning ||
                    form.membres.length === 0 ||
                    !!partageWarning
                "
                class="gap-2"
            >
                <Save class="h-4 w-4" />
                {{ form.processing ? 'Enregistrement…' : 'Enregistrer' }}
            </Button>
        </div>
    </form>

    <!-- Modal membre -->
    <MembreModal
        v-model:visible="showModal"
        :membre="membreEnEdition"
        :telephone-error="
            editingIndex !== null
                ? form.errors?.[`membres.${editingIndex}.telephone`]
                : null
        "
        @confirm="onMembreConfirm"
    />

    <!-- Modal partage -->
    <PartageModal
        v-model:visible="showPartageModal"
        :membres="form.membres"
        :proprietaire-nom="partageProprietaireNom"
        :commission-initiale="form.commission_unitaire_par_pack"
        :montants-initiaux="montantsInitiaux"
        @confirm="onPartageConfirm"
    />
</template>
