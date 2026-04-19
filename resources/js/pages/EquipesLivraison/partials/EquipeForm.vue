<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { formatPhoneDisplay } from '@/lib/utils';
import {
    AlertTriangle,
    Lock,
    Pencil,
    Plus,
    Save,
    Trash2,
} from 'lucide-vue-next';
import AutoComplete from 'primevue/autocomplete';
import InputNumber from 'primevue/inputnumber';
import { useConfirm } from 'primevue/useconfirm';
import { computed, ref } from 'vue';
import MembreModal, { type MembreFormData } from './MembreModal.vue';

interface VehiculeOption {
    value: number;
    label: string;
    immatriculation: string;
    categorie: string;
    type_label: string;
    proprietaire_id: number | null;
    proprietaire_nom: string | null;
}

type Membre = MembreFormData;

interface ProprietaireOption {
    value: number;
    label: string;
    telephone?: string | null;
}

interface FormData {
    nom: string;
    is_active: boolean;
    vehicule_id: number | null;
    proprietaire_id: number | null;
    taux_commission_proprietaire: number | null;
    membres: Membre[];
    errors?: Record<string, string>;
    processing?: boolean;
}

const props = defineProps<{
    form: FormData;
    proprietaires: ProprietaireOption[];
    vehicules: VehiculeOption[];
    tauxProprietaireDefaut: number;
}>();
const emit = defineEmits<{ submit: [] }>();

const confirm = useConfirm();

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
    if (v?.proprietaire_id) {
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

// Modal état

const showModal = ref(false);
const editingIndex = ref<number | null>(null); // null = nouveau membre

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

// Computed

const sommeTaux = computed(() =>
    props.form.membres.reduce((s, m) => s + (m.taux_commission || 0), 0),
);

const principalIndex = computed(() =>
    props.form.membres.findIndex((m) => m.role === 'principal'),
);

const hasPrincipal = computed(() => principalIndex.value >= 0);

const vehiculeHasProprietaire = computed(
    () => !!vehiculeSelected.value?.proprietaire_id,
);

const vehiculeWarning = computed(() => {
    if (!props.form.vehicule_id) {
        return 'Le véhicule est obligatoire.';
    }
    return null;
});

const proprietaireWarning = computed(() => {
    if (!props.form.proprietaire_id) {
        return 'Le propriétaire est obligatoire.';
    }
    return null;
});

const principalWarning = computed(() => {
    const count = props.form.membres.filter(
        (m) => m.role === 'principal',
    ).length;
    if (count === 0)
        return "L'équipe doit avoir un seul livreur principal mais plusieurs assistants.";
    if (count > 1)
        return "L'équipe ne peut avoir qu'un seul livreur principal.";
    return null;
});

const tauxWarning = computed(() => {
    if (props.form.membres.some((m) => Number(m.taux_commission) < 0)) {
        return 'Le taux de commission ne peut pas être négatif.';
    }

    if (
        props.form.membres.length > 0 &&
        Math.abs(totalTauxEquipe.value - 100) > 0.01
    ) {
        return `La répartition doit totaliser 100 % (livreurs + propriétaire). Actuellement : ${totalTauxEquipe.value} %.`;
    }

    return null;
});

const maxTauxDisponible = computed(() => {
    const tauxProp = props.form.taux_commission_proprietaire ?? 0;
    const totalSansMembreEdite = props.form.membres.reduce(
        (sum, membre, index) => {
            if (editingIndex.value !== null && index === editingIndex.value)
                return sum;
            const taux = Number(membre.taux_commission);
            return sum + (Number.isFinite(taux) ? taux : 0);
        },
        0,
    );

    return Math.max(
        0,
        Number((100 - tauxProp - totalSansMembreEdite).toFixed(2)),
    );
});

const totalTauxEquipe = computed(() => {
    const membres = sommeTaux.value;
    const prop = props.form.taux_commission_proprietaire ?? 0;
    return Math.round((membres + prop) * 100) / 100;
});

// Gestion des membres

function onMembreConfirm(data: MembreFormData) {
    const newIsPrincipal = data.role === 'principal';
    const existingPrincipalIdx = props.form.membres.findIndex(
        (m, i) => m.role === 'principal' && i !== editingIndex.value,
    );

    if (newIsPrincipal && existingPrincipalIdx >= 0) {
        // Conflit : un principal existe déjà
        const existing = props.form.membres[existingPrincipalIdx];
        confirm.require({
            message: `Remplacer « ${existing.prenom} ${existing.nom} » comme principal par « ${data.prenom} ${data.nom} » ?`,
            header: 'Remplacer le principal ?',
            icon: 'pi pi-exclamation-triangle',
            rejectLabel: 'Annuler',
            acceptLabel: 'Remplacer',
            accept: () => {
                // Rétrograder l'ancien principal en assistant
                // eslint-disable-next-line vue/no-mutating-props
                props.form.membres[existingPrincipalIdx].role = 'assistant';
                applyMembreData(data);
            },
        });
    } else {
        applyMembreData(data);
    }
}

function applyMembreData(data: MembreFormData) {
    if (editingIndex.value === null) {
        // Ajout
        // eslint-disable-next-line vue/no-mutating-props
        props.form.membres.push({
            ...data,
            ordre: props.form.membres.length,
        });
    } else {
        // Mise a jour
        Object.assign(props.form.membres[editingIndex.value], data);
    }
    // Rafraichir les ordres
    props.form.membres.forEach((m, i) => (m.ordre = i));
}

function removeMembre(index: number) {
    // eslint-disable-next-line vue/no-mutating-props
    props.form.membres.splice(index, 1);
    props.form.membres.forEach((m, i) => (m.ordre = i));
}

// Affichage

function initiales(prenom: string, nom: string): string {
    const p = prenom.trim()[0] ?? '';
    const n = nom.trim()[0] ?? '';
    return (p + n).toUpperCase();
}

function setIsActive(val: boolean | string) {
    // eslint-disable-next-line vue/no-mutating-props
    props.form.is_active = val === true;
}

// Submit

function handleSubmit() {
    if (
        vehiculeWarning.value ||
        proprietaireWarning.value ||
        principalWarning.value ||
        tauxWarning.value
    )
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
                    @complete="searchVehicule"
                    @item-select="onVehiculeSelect(vehiculeSelected)"
                    @clear="onVehiculeClear"
                    placeholder="Nom ou immatriculation…"
                    class="w-full"
                    input-class="w-full"
                    :class="{ 'p-invalid': form.errors?.vehicule_id }"
                    dropdown
                    force-selection
                >
                    <template #option="{ option }">
                        <div class="py-0.5">
                            <div class="leading-tight font-medium">
                                {{ option.label }}
                            </div>
                            <div
                                class="mt-0.5 flex items-center gap-2 text-xs text-muted-foreground"
                            >
                                <span class="font-mono">{{
                                    option.immatriculation
                                }}</span>
                                <span>·</span>
                                <span>{{ option.type_label }}</span>
                                <span>·</span>
                                <span class="capitalize">{{
                                    option.categorie
                                }}</span>
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
                    v-if="vehiculeWarning && form.errors?.vehicule_id"
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
        </div>

        <!-- Nom de l'équipe -->
        <div class="sm:col-span-2">
            <Label for="nom" class="mb-1.5 block">
                Nom de l'équipe
                <span class="text-destructive">*</span>
            </Label>
            <div
                class="flex h-10 w-full items-center justify-between rounded-md border border-input bg-muted/40 px-3 py-2 text-sm"
                :class="{ 'border-destructive': form.errors?.nom }"
            >
                <span
                    :class="
                        form.nom ? 'text-foreground' : 'text-muted-foreground'
                    "
                >
                    {{ form.nom || 'Sélectionnez un véhicule…' }}
                </span>
                <Lock class="h-3.5 w-3.5 shrink-0 text-muted-foreground/60" />
            </div>
            <p v-if="form.errors?.nom" class="mt-1 text-xs text-destructive">
                {{ form.errors.nom }}
            </p>
        </div>

        <!-- Identification -->
        <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3
                class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
            >
                Identification
            </h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <!-- Propriétaire -->
                <div>
                    <Label for="proprietaire_id" class="mb-1.5 block">
                        Propriétaire
                        <span class="text-destructive">*</span>
                    </Label>

                    <!-- Verrouillé : auto-renseigné depuis le véhicule -->
                    <div
                        v-if="vehiculeHasProprietaire"
                        class="flex h-10 w-full items-center justify-between rounded-md border border-input bg-muted/40 px-3 py-2 text-sm"
                        :class="{
                            'border-destructive': form.errors?.proprietaire_id,
                        }"
                    >
                        <span class="text-foreground">{{
                            proprietaireSelected?.label
                        }}</span>
                        <Lock
                            class="h-3.5 w-3.5 shrink-0 text-muted-foreground/60"
                        />
                    </div>

                    <!-- Éditable : le véhicule n'a pas de propriétaire lié -->
                    <AutoComplete
                        v-else
                        v-model="proprietaireSelected"
                        input-id="proprietaire_id"
                        :suggestions="proprietaireSuggests"
                        option-label="label"
                        @complete="_searchProprietaire"
                        @item-select="
                            _onProprietaireSelect(proprietaireSelected)
                        "
                        @clear="_onProprietaireClear"
                        placeholder="Nom ou téléphone…"
                        class="w-full"
                        input-class="w-full"
                        :class="{ 'p-invalid': form.errors?.proprietaire_id }"
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
                                class="px-1 py-0.5 text-sm text-muted-foreground"
                            >
                                Aucun résultat
                            </div>
                        </template>
                    </AutoComplete>

                    <p
                        v-if="form.errors?.proprietaire_id"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ form.errors.proprietaire_id }}
                    </p>
                </div>

                <!-- Taux propriétaire -->
                <div>
                    <Label
                        for="taux_commission_proprietaire"
                        class="mb-1.5 block"
                    >
                        Taux propriétaire (%)
                        <span class="text-destructive">*</span>
                    </Label>
                    <!-- eslint-disable vue/no-mutating-props -->
                    <InputNumber
                        id="taux_commission_proprietaire"
                        v-model="form.taux_commission_proprietaire"
                        :min="0"
                        :max="100"
                        :max-fraction-digits="2"
                        suffix=" %"
                        class="w-full"
                        :class="{
                            'p-invalid':
                                form.errors?.taux_commission_proprietaire,
                        }"
                    />
                    <!-- eslint-enable vue/no-mutating-props -->
                    <p
                        v-if="form.membres.length > 0"
                        class="mt-1 text-xs"
                        :class="
                            Math.abs(totalTauxEquipe - 100) > 0.01
                                ? 'text-amber-600'
                                : 'text-emerald-600'
                        "
                    >
                        Total équipe + propriétaire : {{ totalTauxEquipe }}%
                        {{ Math.abs(totalTauxEquipe - 100) <= 0.01 ? '✓' : '' }}
                    </p>
                    <p
                        v-if="form.errors?.taux_commission_proprietaire"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ form.errors.taux_commission_proprietaire }}
                    </p>
                </div>

                <div class="sm:col-span-2">
                    <h4
                        class="mb-2 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Statut
                    </h4>
                    <div class="flex items-start gap-3">
                        <Checkbox
                            id="is_active"
                            :model-value="Boolean(form.is_active)"
                            @update:model-value="setIsActive($event)"
                        />
                        <div>
                            <Label
                                for="is_active"
                                class="cursor-pointer font-medium"
                                >Actif</Label
                            >
                            <p class="text-xs text-muted-foreground">
                                Décochez pour désactiver l'équipe.
                            </p>
                        </div>
                    </div>
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
                        Σ taux livreurs :
                        <span
                            class="font-semibold"
                            :class="
                                totalTauxEquipe > 100
                                    ? 'text-destructive'
                                    : 'text-foreground'
                            "
                            >{{ sommeTaux }}%</span
                        >
                        <span class="ml-1">
                            (
                            <span
                                :class="
                                    maxTauxDisponible <= 0
                                        ? 'font-semibold text-destructive'
                                        : ''
                                "
                                >{{ maxTauxDisponible }}%</span
                            >
                            disponible)
                        </span>
                    </p>
                </div>
                <Button type="button" size="sm" @click="openNewMembre">
                    <Plus class="mr-1.5 h-3.5 w-3.5" />
                    Ajouter un membre
                </Button>
            </div>

            <!-- Alerte propriétaire -->
            <div
                v-if="proprietaireWarning"
                class="mb-4 flex items-center gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-300"
            >
                <AlertTriangle class="h-3.5 w-3.5 shrink-0" />
                {{ proprietaireWarning }}
            </div>

            <!-- Alerte principal -->
            <div
                v-if="principalWarning"
                class="mb-4 flex items-center gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-300"
            >
                <AlertTriangle class="h-3.5 w-3.5 shrink-0" />
                {{ principalWarning }}
            </div>

            <div
                v-if="tauxWarning"
                class="mb-4 flex items-center gap-2 rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-xs text-destructive"
            >
                <AlertTriangle class="h-3.5 w-3.5 shrink-0" />
                {{ tauxWarning }}
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
                    <!-- Zone gauche : avatar + nom + téléphone -->
                    <div class="flex min-w-0 flex-1 items-center gap-3">
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                            :class="
                                membre.role === 'principal'
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

                    <!-- Zone milieu : rôle centré -->
                    <div class="w-28 shrink-0 text-center">
                        <span
                            class="inline-block rounded-sm px-2 py-0.5 text-[10px] font-semibold tracking-wide uppercase"
                            :class="
                                membre.role === 'principal'
                                    ? 'bg-primary/10 text-primary'
                                    : 'bg-muted text-muted-foreground'
                            "
                        >
                            {{ membre.role }}
                        </span>
                    </div>

                    <!-- Zone droite : taux + actions -->
                    <div class="flex shrink-0 items-center gap-3">
                        <span
                            class="w-12 text-right font-mono text-sm font-medium tabular-nums"
                        >
                            {{ membre.taux_commission }}%
                        </span>
                        <div class="flex gap-0.5">
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
                    !!principalWarning ||
                    !!tauxWarning
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
        :has-principal="hasPrincipal && editingIndex !== principalIndex"
        :max-taux="maxTauxDisponible"
        :telephone-error="
            editingIndex !== null
                ? form.errors?.[`membres.${editingIndex}.telephone`]
                : null
        "
        @confirm="onMembreConfirm"
    />
</template>
