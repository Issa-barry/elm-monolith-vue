<script setup lang="ts">
import CommissionShareEditor, {
    type CommissionShareMembre,
} from '@/components/commission/CommissionShareEditor.vue';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/vue3';
import {
    Check,
    ChevronLeft,
    ChevronRight,
    Plus,
    Trash2,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputText from 'primevue/inputtext';
import { computed, reactive, ref, watch } from 'vue';

const GUINEA_PREFIX = '+224';
const ROLES = [
    { value: 'chauffeur', label: 'Chauffeur' },
    { value: 'convoyeur', label: 'Convoyeur' },
];

interface ProprietaireOption {
    value: string;
    label: string;
    telephone?: string;
}

interface VehiculeInfo {
    id: string;
    nom_vehicule: string;
    immatriculation: string;
    proprietaire_id: string | null;
    proprietaire_nom: string | null;
    proprietaire_est_entreprise?: boolean;
}

interface MembreExistant {
    livreur_id: string | null;
    // Identité civile jamais utilisée côté Eau La Maman — seul un nom complet
    // ou surnom facultatif est saisi/affiché (voir Livreur::$fillable côté back).
    nom_complet: string | null;
    telephone: string;
    role: string;
    part_pourcentage: number;
    ordre: number;
}

interface EquipeExistante {
    id: string;
    is_active: boolean;
    proprietaire_id: string | null;
    proprietaire_nom: string | null;
    membres: MembreExistant[];
}

interface MembreLigne {
    livreur_id: string | null;
    role: string;
    nom_complet: string; // facultatif — surnom ou désignation opérationnelle
    telephone: string; // 9 chiffres locaux
    part_pourcentage: number;
    ordre: number;
    _errors: Partial<Record<'role' | 'telephone', string>>;
}

interface BaremeCommission {
    proprietaire: number | null;
    livraison: number | null;
}

const props = defineProps<{
    visible: boolean;
    vehicule: VehiculeInfo;
    equipe: EquipeExistante | null;
    proprietaires: ProprietaireOption[];
    baremeCommission: BaremeCommission;
}>();

const emit = defineEmits<{
    'update:visible': [boolean];
}>();

// ── State ───────────────────────────────────────────────────────────────────

const step = ref(1);
const isSubmitting = ref(false);
const serverErrors = reactive<Record<string, string>>({});
const showConfirmClose = ref(false);
const hasChanges = ref(false);

function markChanged() {
    hasChanges.value = true;
}

function requestClose() {
    if (hasChanges.value) {
        showConfirmClose.value = true;
    } else {
        emit('update:visible', false);
    }
}

function confirmClose() {
    showConfirmClose.value = false;
    hasChanges.value = false;
    emit('update:visible', false);
}

const membres = ref<MembreLigne[]>([]);

// ── Computed ────────────────────────────────────────────────────────────────

// Un partage propriétaire est proposé dès que le véhicule a un propriétaire assigné (interne
// par défaut ou tiers) — jamais dérivé de la catégorie du véhicule, cf. EquipeLivraisonController
// (le propriétaire interne par défaut peut lui aussi avoir une part de commission).
const hasProprietaire = computed(() => !!props.vehicule.proprietaire_id);

const proprietaireNom = computed(() => {
    if (!hasProprietaire.value) return null;
    const p = props.proprietaires.find(
        (p) => p.value === props.vehicule.proprietaire_id,
    );
    return p?.label ?? props.vehicule.proprietaire_nom ?? null;
});

const stepTitle = computed(() =>
    props.equipe ? "Modifier l'équipe" : "Configurer l'équipe",
);

// Commission propriétaire — montant fixe du barème (Paramètres → Commissions),
// jamais saisi ici : le propriétaire ne participe plus au partage des livreurs
// (décision AMOA #1 — deux enveloppes distinctes, plus un pot commun).
const montantProprietaire = computed(
    () => props.baremeCommission.proprietaire,
);
// Montant Livraison — indicatif uniquement dans cette popup : sert de référence
// pour la saisie Montant/% des livreurs, le montant réel dépend de la catégorie
// vendue à chaque vente (cf. conception cible §0.2.2/§0.3).
const montantLivraison = computed(() => props.baremeCommission.livraison);

// Poids informatif du propriétaire dans le total des deux barèmes affichés —
// dénominateur différent de la part des livreurs dans l'enveloppe Livraison
// (cf. conception cible §0.3.2, ne jamais confondre les deux pourcentages).
const partProprietaireDuBareme = computed(() => {
    const p = montantProprietaire.value;
    const l = montantLivraison.value;
    if (p === null || l === null || p + l <= 0) return null;
    return parseFloat(((p / (p + l)) * 100).toFixed(2));
});

// ── Init ────────────────────────────────────────────────────────────────────

watch(
    () => props.visible,
    (val) => {
        if (!val) return;
        step.value = 1;
        hasChanges.value = false;
        showConfirmClose.value = false;
        Object.keys(serverErrors).forEach((k) => delete serverErrors[k]);
        isSubmitting.value = false;

        if (props.equipe) {
            membres.value = props.equipe.membres.map((m) => ({
                livreur_id: m.livreur_id,
                role: m.role,
                nom_complet: m.nom_complet ?? '',
                telephone: m.telephone.startsWith(GUINEA_PREFIX)
                    ? m.telephone.slice(GUINEA_PREFIX.length)
                    : m.telephone.replace(/\D/g, '').slice(-9),
                part_pourcentage: m.part_pourcentage,
                ordre: m.ordre,
                _errors: {},
            }));
        } else {
            membres.value = [
                {
                    livreur_id: null,
                    role: '',
                    nom_complet: '',
                    telephone: '',
                    part_pourcentage: 0,
                    ordre: 0,
                    _errors: {},
                },
            ];
        }
    },
);

// ── Étape 1 : Membres ───────────────────────────────────────────────────────

function addLigne() {
    markChanged();
    membres.value.push({
        livreur_id: null,
        role: '',
        nom_complet: '',
        telephone: '',
        part_pourcentage: 0,
        ordre: membres.value.length,
        _errors: {},
    });
}

function removeLigne(idx: number) {
    markChanged();
    membres.value.splice(idx, 1);
    membres.value.forEach((m, i) => (m.ordre = i));
}

function handlePhoneKeydown(e: KeyboardEvent) {
    const pass = [
        'Backspace',
        'Delete',
        'Tab',
        'Escape',
        'Enter',
        'ArrowLeft',
        'ArrowRight',
        'Home',
        'End',
    ];
    if (pass.includes(e.key)) return;
    if (
        (e.ctrlKey || e.metaKey) &&
        ['a', 'c', 'v', 'x'].includes(e.key.toLowerCase())
    )
        return;
    if (!/^\d$/.test(e.key)) e.preventDefault();
}

function onPhoneInput(e: Event, idx: number) {
    markChanged();
    const raw = (e.target as HTMLInputElement).value.replace(/\D/g, '');
    const local = raw.slice(0, 9);
    membres.value[idx].telephone = local;
    (e.target as HTMLInputElement).value = local;
}

function validateStep1(): boolean {
    const phones = new Set<string>();
    let valid = true;

    membres.value.forEach((m) => {
        m._errors = {};
        if (!m.role) {
            m._errors.role = 'Rôle requis';
            valid = false;
        }
        if (!m.telephone || !/^\d{9}$/.test(m.telephone)) {
            m._errors.telephone = '9 chiffres requis';
            valid = false;
        } else if (phones.has(m.telephone)) {
            m._errors.telephone = 'Numéro déjà utilisé';
            valid = false;
        } else {
            phones.add(m.telephone);
        }
    });

    return valid && membres.value.length > 0;
}

function goToStep2() {
    if (!validateStep1()) return;
    markChanged();
    // Un seul membre : 100 % automatiquement (rien à répartir).
    if (membres.value.length === 1 && membres.value[0].part_pourcentage === 0) {
        membres.value[0].part_pourcentage = 100;
    }
    step.value = 2;
}

// ── Étape 2 : Partage (livreurs uniquement) ─────────────────────────────────

const partageMembres = computed<CommissionShareMembre[]>(() => {
    const roleCounts: Record<string, number> = {};
    return membres.value.map((m, i) => {
        roleCounts[m.role] = (roleCounts[m.role] ?? 0) + 1;
        return {
            id: `membre-${i}`,
            label: membreLabel(m.role, roleCounts[m.role], m.nom_complet),
            part_pourcentage: m.part_pourcentage,
        };
    });
});

function onPartageUpdate(list: CommissionShareMembre[]) {
    markChanged();
    list.forEach((l) => {
        const idx = parseInt(l.id.replace('membre-', ''), 10);
        if (membres.value[idx]) {
            membres.value[idx].part_pourcentage = l.part_pourcentage;
        }
    });
}

const totalPartage = computed(() =>
    membres.value.reduce((s, m) => s + (m.part_pourcentage || 0), 0),
);

const partageValide = computed(() => Math.abs(totalPartage.value - 100) < 0.01);

function goToStep3() {
    if (!partageValide.value) return;
    step.value = 3;
}

// ── Étape 3 : Récapitulatif ─────────────────────────────────────────────────

/** "Chauffeur-1" / "Convoyeur-2" — désignation opérationnelle par défaut. */
function roleOrdinal(role: string, numero: number): string {
    return role === 'chauffeur' ? `Chauffeur-${numero}` : `Convoyeur-${numero}`;
}

/**
 * Libellé "Membre" affiché partout côté Eau La Maman : jamais construit à
 * partir de prenom/nom (identité civile non utilisée sur ce projet — voir
 * EquipeLivraisonController). "Chauffeur-1" seul si nom_complet est vide,
 * "Chauffeur-1 — Mamadou SY" s'il est renseigné.
 */
function membreLabel(role: string, numero: number, nomComplet: string): string {
    const ordinal = roleOrdinal(role, numero);
    return nomComplet.trim() ? `${ordinal} — ${nomComplet.trim()}` : ordinal;
}

function roleLabel(role: string, index: number): string {
    const count = membres.value
        .slice(0, index + 1)
        .filter((m) => m.role === role).length;
    return roleOrdinal(role, count);
}

function formatGNF(val: number | null): string {
    if (val === null) return 'Non configuré';
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

function formatPhone(local: string): string {
    const d = local.replace(/\D/g, '');
    return `+224 ${d.slice(0, 3)} ${d.slice(3, 5)} ${d.slice(5, 7)} ${d.slice(7)}`;
}

/** Montant estimé d'un membre = barème Livraison (indicatif) × sa part. */
function montantEstime(pct: number): string {
    if (montantLivraison.value === null) return `${pct} %`;
    const montant = Math.round((pct / 100) * montantLivraison.value);
    return `${formatGNF(montant)} • ${pct} %`;
}

// ── Soumission ──────────────────────────────────────────────────────────────

function buildPayload() {
    return {
        vehicule_id: props.vehicule.id,
        // proprietaire_id n'est jamais envoyé : toujours dérivé côté serveur depuis
        // Vehicule::proprietaire_id (cf. EquipeLivraisonController), pour ne jamais désynchroniser
        // l'équipe du propriétaire réel du véhicule.
        is_active: props.equipe?.is_active ?? true,
        membres: membres.value.map((m, i) => ({
            livreur_id: m.livreur_id ?? null,
            nom_complet: m.nom_complet.trim() || null,
            telephone: `${GUINEA_PREFIX}${m.telephone}`,
            role: m.role,
            part_pourcentage: m.part_pourcentage,
            ordre: i,
        })),
    };
}

function submit() {
    if (isSubmitting.value) return;
    isSubmitting.value = true;
    Object.keys(serverErrors).forEach((k) => delete serverErrors[k]);

    const payload = buildPayload();
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            emit('update:visible', false);
            isSubmitting.value = false;
        },
        onError: (errors: Record<string, string>) => {
            isSubmitting.value = false;
            Object.assign(serverErrors, errors);
            step.value = 1;
        },
    };

    if (props.equipe) {
        router.patch(
            `/backoffice/equipes-livraison/${props.equipe.id}`,
            payload,
            options,
        );
    } else {
        router.post('/backoffice/equipes-livraison', payload, options);
    }
}

const hasStep1Errors = computed(() =>
    membres.value.some((m) => Object.keys(m._errors).length > 0),
);
</script>

<template>
    <!-- ── Confirmation fermeture ────────────────────────────────────────── -->
    <Dialog
        v-model:visible="showConfirmClose"
        modal
        header="Quitter sans enregistrer ?"
        :style="{ width: 'min(400px, 90vw)' }"
        append-to="body"
        :closable="false"
    >
        <p class="text-sm text-muted-foreground">
            Vos modifications seront perdues.
        </p>
        <template #footer>
            <div class="flex w-full items-center justify-between">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="showConfirmClose = false"
                >
                    Continuer l'édition
                </Button>
                <Button
                    type="button"
                    variant="destructive"
                    size="sm"
                    @click="confirmClose"
                >
                    Quitter
                </Button>
            </div>
        </template>
    </Dialog>

    <!-- ── Modal principal ───────────────────────────────────────────────── -->
    <Dialog
        :visible="visible"
        modal
        :header="stepTitle"
        :style="{ width: 'min(960px, 95vw)' }"
        :dismissable-mask="false"
        :closable="true"
        @update:visible="
            (val) => {
                if (!val) requestClose();
            }
        "
    >
        <!-- ── Indicateur d'étapes ────────────────────────────────────────── -->
        <div class="mb-6 flex items-center gap-2">
            <template v-for="n in 3" :key="n">
                <div class="flex shrink-0 items-center gap-2">
                    <div
                        class="flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold transition-colors"
                        :class="
                            step > n
                                ? 'bg-emerald-500 text-white'
                                : step === n
                                  ? 'bg-primary text-primary-foreground'
                                  : 'bg-muted text-muted-foreground'
                        "
                    >
                        <Check v-if="step > n" class="h-3.5 w-3.5" />
                        <span v-else>{{ n }}</span>
                    </div>
                    <span
                        class="hidden text-sm sm:inline"
                        :class="
                            step === n
                                ? 'font-medium text-foreground'
                                : 'text-muted-foreground'
                        "
                    >
                        {{
                            n === 1
                                ? 'Membres'
                                : n === 2
                                  ? 'Partage'
                                  : 'Récapitulatif'
                        }}
                    </span>
                </div>
                <div v-if="n < 3" class="h-px flex-1 bg-border" />
            </template>
        </div>

        <!-- Erreurs serveur -->
        <div
            v-if="Object.keys(serverErrors).length > 0"
            class="mb-4 rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive"
        >
            <p v-for="(msg, key) in serverErrors" :key="key">{{ msg }}</p>
        </div>

        <!-- ── Étape 1 : Membres ─────────────────────────────────────────── -->
        <div v-if="step === 1" class="space-y-4">
            <p v-if="membres.length > 0" class="text-sm text-muted-foreground">
                <span class="font-medium text-foreground">{{
                    membres.length
                }}</span>
                membre{{ membres.length !== 1 ? 's' : '' }}
            </p>

            <div
                v-if="membres.length === 0"
                class="rounded-lg border border-dashed py-12 text-center text-sm text-muted-foreground"
            >
                Aucun membre. Cliquez sur « + Ajouter un membre » ci-dessous
                pour commencer.
            </div>

            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full min-w-[680px] text-sm">
                    <thead>
                        <tr
                            class="border-b bg-muted/40 text-left text-xs font-medium text-muted-foreground"
                        >
                            <th class="w-36 px-3 py-2.5">Rôle *</th>
                            <th class="px-3 py-2.5">Nom complet ou surnom</th>
                            <th class="w-52 px-3 py-2.5">Téléphone *</th>
                            <th class="w-10 px-3 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="(m, i) in membres"
                            :key="i"
                            class="align-top"
                        >
                            <!-- Rôle -->
                            <td class="px-3 py-2">
                                <Dropdown
                                    v-model="m.role"
                                    :options="ROLES"
                                    option-label="label"
                                    option-value="value"
                                    placeholder="Rôle…"
                                    class="w-full"
                                    :class="{ 'p-invalid': m._errors.role }"
                                    append-to="body"
                                    :data-testid="`role-dropdown-${i}`"
                                    @change="markChanged"
                                />
                                <p
                                    v-if="m._errors.role"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{ m._errors.role }}
                                </p>
                            </td>

                            <!-- Nom complet ou surnom (facultatif) -->
                            <td class="px-3 py-2">
                                <InputText
                                    v-model="m.nom_complet"
                                    class="w-full"
                                    placeholder="Ex : Mamadou SY "
                                    :data-testid="`nom-complet-${i}`"
                                    @input="markChanged"
                                />
                            </td>

                            <!-- Téléphone -->
                            <td class="px-3 py-2">
                                <div
                                    class="flex h-9 overflow-hidden rounded-md border"
                                    :class="
                                        m._errors.telephone
                                            ? 'border-destructive'
                                            : 'border-input'
                                    "
                                >
                                    <span
                                        class="flex shrink-0 items-center gap-1 border-r bg-muted px-2 text-xs text-muted-foreground select-none"
                                    >
                                        <img
                                            src="https://flagcdn.com/16x12/gn.png"
                                            width="16"
                                            height="12"
                                            alt="GN"
                                        />
                                        +224
                                    </span>
                                    <input
                                        type="tel"
                                        inputmode="numeric"
                                        maxlength="9"
                                        :value="m.telephone"
                                        placeholder="9 chiffres"
                                        class="min-w-0 flex-1 bg-background px-2 text-sm outline-none placeholder:text-muted-foreground"
                                        :data-testid="`telephone-${i}`"
                                        @input="onPhoneInput($event, i)"
                                        @keydown="handlePhoneKeydown"
                                    />
                                </div>
                                <p
                                    v-if="m._errors.telephone"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{ m._errors.telephone }}
                                </p>
                            </td>

                            <!-- Actions -->
                            <td class="px-3 py-2">
                                <button
                                    type="button"
                                    class="mt-0.5 flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                    @click="removeLigne(i)"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-if="hasStep1Errors" class="text-xs text-destructive">
                Corrigez les erreurs dans le tableau avant de continuer.
            </p>
        </div>

        <!-- ── Étape 2 : Partage (livreurs uniquement) ─────────────────────── -->
        <div v-else-if="step === 2" class="space-y-5">
            <!-- Commission livraison — lecture seule, vient du barème -->
            <div class="rounded-lg border bg-muted/30 p-3">
                <p
                    class="text-xs font-medium tracking-wider text-muted-foreground uppercase"
                >
                    Commission livraison
                </p>
                <p class="mt-1 text-sm font-semibold">
                    {{ formatGNF(montantLivraison) }}
                    <span class="font-normal text-muted-foreground"
                        >/ unité</span
                    >
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Défini dans Paramètres → Commissions. Répartissez-la
                    ci-dessous entre les livreurs.
                </p>
            </div>

            <CommissionShareEditor
                :model-value="partageMembres"
                :enveloppe-montant="montantLivraison"
                @update:model-value="onPartageUpdate"
            />
        </div>

        <!-- ── Étape 3 : Récapitulatif ───────────────────────────────────── -->
        <div v-else-if="step === 3" class="space-y-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div class="rounded-lg border bg-muted/30 p-3">
                    <p
                        class="text-xs font-medium tracking-wider text-muted-foreground uppercase"
                    >
                        Véhicule
                    </p>
                    <p class="mt-1 text-sm font-semibold">
                        {{ vehicule.nom_vehicule }}
                    </p>
                    <p class="font-mono text-xs text-muted-foreground">
                        {{ vehicule.immatriculation }}
                    </p>
                </div>

                <!-- Propriétaire — montant + % informatif, jamais éditable ici -->
                <div
                    v-if="hasProprietaire && proprietaireNom"
                    class="rounded-lg border bg-muted/30 p-3"
                >
                    <p
                        class="text-xs font-medium tracking-wider text-muted-foreground uppercase"
                    >
                        Propriétaire
                    </p>
                    <p class="mt-1 text-sm font-semibold">
                        {{ proprietaireNom }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ formatGNF(montantProprietaire) }}
                        <span v-if="partProprietaireDuBareme !== null">
                            • {{ partProprietaireDuBareme }} %</span
                        >
                    </p>
                </div>

                <div class="rounded-lg border bg-muted/30 p-3">
                    <p
                        class="text-xs font-medium tracking-wider text-muted-foreground uppercase"
                    >
                        Commission livraison
                    </p>
                    <p class="mt-1 text-sm font-semibold">
                        {{ formatGNF(montantLivraison) }}
                    </p>
                </div>
            </div>

            <div class="overflow-hidden rounded-lg border">
                <div class="border-b bg-muted/30 px-4 py-2.5">
                    <p
                        class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Membres ({{ membres.length }})
                    </p>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr
                            class="border-b text-left text-xs text-muted-foreground"
                        >
                            <th class="px-4 py-2 font-medium">Membre</th>
                            <th class="px-4 py-2 font-medium">Téléphone</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Part
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="(m, i) in membres" :key="i">
                            <td class="px-4 py-2.5 font-medium">
                                {{
                                    m.nom_complet.trim()
                                        ? `${roleLabel(m.role, i)} — ${m.nom_complet.trim()}`
                                        : roleLabel(m.role, i)
                                }}
                            </td>
                            <td
                                class="px-4 py-2.5 font-mono text-xs text-muted-foreground"
                            >
                                {{ formatPhone(m.telephone) }}
                            </td>
                            <td
                                class="px-4 py-2.5 text-right font-mono text-xs"
                            >
                                {{ montantEstime(m.part_pourcentage) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── Footer navigation ─────────────────────────────────────────── -->
        <template #footer>
            <div class="flex w-full items-center justify-between">
                <!-- Bouton gauche -->
                <Button
                    v-if="step > 1"
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="step--"
                >
                    <ChevronLeft class="mr-1 h-4 w-4" />
                    Retour
                </Button>
                <Button
                    v-else
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="addLigne"
                >
                    <Plus class="mr-1.5 h-4 w-4" />
                    Ajouter un membre
                </Button>

                <!-- Bouton droit -->
                <Button
                    v-if="step === 1"
                    type="button"
                    size="sm"
                    :disabled="membres.length === 0"
                    @click="goToStep2"
                >
                    Suivant
                    <ChevronRight class="ml-1 h-4 w-4" />
                </Button>

                <Button
                    v-else-if="step === 2"
                    type="button"
                    size="sm"
                    :disabled="!partageValide"
                    @click="goToStep3"
                >
                    Suivant
                    <ChevronRight class="ml-1 h-4 w-4" />
                </Button>

                <Button
                    v-else
                    type="button"
                    size="sm"
                    :disabled="isSubmitting"
                    @click="submit"
                >
                    {{
                        isSubmitting
                            ? 'Enregistrement…'
                            : "Enregistrer l'équipe"
                    }}
                </Button>
            </div>
        </template>
    </Dialog>
</template>
