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
    montant_par_pack: number;
    ordre: number;
}

/** Partage Livraison déjà enregistré, groupé par catégorie. */
interface PartageCategorieExistant {
    categorie_id: string;
    parts: Array<{ livreur_id: string; part_pourcentage: number }>;
}

interface EquipeExistante {
    id: string;
    is_active: boolean;
    proprietaire_id: string | null;
    proprietaire_nom: string | null;
    membres: MembreExistant[];
    partages_categorie: PartageCategorieExistant[];
}

interface MembreLigne {
    livreur_id: string | null;
    role: string;
    nom_complet: string; // facultatif — surnom ou désignation opérationnelle
    telephone: string; // 9 chiffres locaux
    montant_par_pack: number;
    ordre: number;
    _errors: Partial<Record<'role' | 'telephone', string>>;
}

/** Barème Propriétaire ET Livraison résolus pour une
 * catégorie (cf. conception cible : les barèmes varient par catégorie, donc
 * ni un montant Propriétaire unique ni un partage Livraison unique ne sont
 * valables pour tout le véhicule — jamais de montant global blended). Une
 * catégorie n'apparaît ici que si au moins un des deux montants est > 0. */
interface BaremeCommissionCategorie {
    categorie_id: string;
    categorie_nom: string;
    montant_proprietaire: number;
    montant_livraison: number;
}

const props = defineProps<{
    visible: boolean;
    vehicule: VehiculeInfo;
    equipe: EquipeExistante | null;
    proprietaires: ProprietaireOption[];
    baremesCommissionCategories: BaremeCommissionCategorie[];
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

// Sous-ensemble des catégories nécessitant réellement un
// partage entre livreurs (Livraison > 0). Une catégorie où seul le
// Propriétaire a un barème positif reste affichée (cf. baremesCommissionCategories),
// mais sans tableau de répartition : rien à partager entre livreurs pour elle.
const categoriesAvecPartageLivraison = computed(() =>
    props.baremesCommissionCategories.filter((c) => c.montant_livraison > 0),
);

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
                montant_par_pack: m.montant_par_pack,
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
                    montant_par_pack: 0,
                    ordre: 0,
                    _errors: {},
                },
            ];
        }
    },
);

// ── Étape 1 : Membres ───────────────────────────────────────────────────

function addLigne() {
    markChanged();
    membres.value.push({
        livreur_id: null,
        role: '',
        nom_complet: '',
        telephone: '',
        montant_par_pack: 0,
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
    initPartagesParCategorie();
    step.value = 2;
}

// ── Étape 2 : Partage livreurs PAR CATÉGORIE, propriétaire hors partage ─────
// Chaque catégorie ayant son propre barème Livraison (Paramètres →
// Commissions), son partage entre livreurs est lui aussi défini
// indépendamment — jamais un seul pourcentage valable pour toute la commande
// (décision AMOA post-Phase 2).

const partagesParCategorie = ref<Record<string, CommissionShareMembre[]>>({});

function membreLabels(): string[] {
    const roleCounts: Record<string, number> = {};
    return membres.value.map((m) => {
        roleCounts[m.role] = (roleCounts[m.role] ?? 0) + 1;
        return membreLabel(m.role, roleCounts[m.role], m.nom_complet);
    });
}

function initPartagesParCategorie() {
    const labels = membreLabels();
    const result: Record<string, CommissionShareMembre[]> = {};

    for (const cat of categoriesAvecPartageLivraison.value) {
        const existant = props.equipe?.partages_categorie?.find(
            (pc) => pc.categorie_id === cat.categorie_id,
        );

        const parts = membres.value.map((m, i) => {
            const partExistante = m.livreur_id
                ? existant?.parts.find((p) => p.livreur_id === m.livreur_id)
                : undefined;
            return {
                id: `membre-${i}`,
                label: labels[i],
                part_pourcentage: partExistante?.part_pourcentage ?? 0,
            };
        });

        // Un seul membre pour cette catégorie : 100 % automatiquement (rien à répartir).
        if (parts.length === 1 && parts[0].part_pourcentage === 0) {
            parts[0].part_pourcentage = 100;
        }

        result[cat.categorie_id] = parts;
    }

    partagesParCategorie.value = result;
}

function onPartageCategorieUpdate(
    categorieId: string,
    list: CommissionShareMembre[],
) {
    markChanged();
    partagesParCategorie.value = {
        ...partagesParCategorie.value,
        [categorieId]: list,
    };
}

// ── Partage : validité ───────────────────────────────────────────────────

// CHAQUE catégorie ayant un barème Livraison > 0 doit totaliser 100 % —
// aucune n'est facultative, jamais une répartition égale déduite pour celle
// qu'on aurait oublié de configurer. Les catégories où seul le Propriétaire a
// un barème positif sont exclues : rien à répartir entre livreurs pour elles.
// Si la liste est vide, il n'y a simplement rien à répartir — équipe valide.
const partageValide = computed(() =>
    categoriesAvecPartageLivraison.value.every((cat) => {
        const parts = partagesParCategorie.value[cat.categorie_id] ?? [];
        const total = parts.reduce((s, p) => s + (p.part_pourcentage || 0), 0);
        return parts.length > 0 && Math.abs(total - 100) < 0.01;
    }),
);

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

/** Montant estimé d'un membre pour une catégorie = barème
 * Livraison de cette catégorie × sa part. */
function montantEstimeCategorie(
    categorieId: string,
    membreIndex: number,
): string {
    const cat = categoriesAvecPartageLivraison.value.find(
        (c) => c.categorie_id === categorieId,
    );
    const part = partagesParCategorie.value[categorieId]?.[membreIndex];
    if (!cat || !part) return '—';

    const montant = Math.round(
        (part.part_pourcentage / 100) * cat.montant_livraison,
    );
    return `${formatGNF(montant)} • ${part.part_pourcentage} %`;
}

// ── Soumission ──────────────────────────────────────────────────────────────

function buildPayload() {
    const base = {
        vehicule_id: props.vehicule.id,
        // proprietaire_id n'est jamais envoyé : toujours dérivé côté serveur depuis
        // Vehicule::proprietaire_id (cf. EquipeLivraisonController), pour ne jamais désynchroniser
        // l'équipe du propriétaire réel du véhicule.
        is_active: props.equipe?.is_active ?? true,
    };

    return {
        ...base,
        membres: membres.value.map((m, i) => ({
            livreur_id: m.livreur_id ?? null,
            nom_complet: m.nom_complet.trim() || null,
            telephone: `${GUINEA_PREFIX}${m.telephone}`,
            role: m.role,
            ordre: i,
        })),
        partages_categorie: categoriesAvecPartageLivraison.value.map((cat) => ({
            categorie_id: cat.categorie_id,
            parts: (partagesParCategorie.value[cat.categorie_id] ?? []).map(
                (p) => ({
                    membre_ordre: parseInt(p.id.replace('membre-', ''), 10),
                    part_pourcentage: p.part_pourcentage,
                }),
            ),
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

        <!-- ── Étape 1 : Membres ─────────────────────────────────────────────── -->
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

        <!-- ── Étape 2 : Barèmes + partage livreurs PAR CATÉGORIE ──────────── -->
        <div v-else-if="step === 2" class="space-y-5">
            <!-- Chaque catégorie a son propre barème Propriétaire ET Livraison
                 (Paramètres → Commissions) — jamais un montant global blended.
                 Le Propriétaire reste toujours informatif et non modifiable ici ;
                 un tableau de répartition n'apparaît que si Livraison > 0 pour
                 cette catégorie (rien à partager sinon). -->
            <div
                v-if="baremesCommissionCategories.length === 0"
                class="rounded-lg border border-dashed py-8 text-center text-sm text-muted-foreground"
            >
                Aucun barème de commission actif pour ce véhicule (Propriétaire
                et Livraison à 0 GNF ou non configurés dans Paramètres →
                Commissions).
            </div>

            <div
                v-for="cat in baremesCommissionCategories"
                :key="cat.categorie_id"
                class="space-y-2"
            >
                <div class="rounded-lg border bg-muted/30 p-3">
                    <p
                        class="text-xs font-medium tracking-wider text-muted-foreground uppercase"
                    >
                        {{ cat.categorie_nom }}
                    </p>
                    <p
                        v-if="hasProprietaire"
                        class="mt-1 text-sm font-semibold text-primary"
                    >
                        {{ formatGNF(cat.montant_proprietaire) }}
                        <span class="font-normal text-muted-foreground"
                            >/ unité — Propriétaire ({{
                                proprietaireNom
                            }})</span
                        >
                    </p>
                    <p class="mt-1 text-sm font-semibold">
                        {{ formatGNF(cat.montant_livraison) }}
                        <span class="font-normal text-muted-foreground"
                            >/ unité — Livraison</span
                        >
                    </p>
                    <p
                        v-if="cat.montant_livraison <= 0"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        Aucune répartition livreurs nécessaire pour cette
                        catégorie.
                    </p>
                </div>

                <CommissionShareEditor
                    v-if="cat.montant_livraison > 0"
                    :model-value="partagesParCategorie[cat.categorie_id] ?? []"
                    :enveloppe-montant="cat.montant_livraison"
                    @update:model-value="
                        (list) =>
                            onPartageCategorieUpdate(cat.categorie_id, list)
                    "
                />
            </div>
        </div>

        <!-- ── Étape 3 : Récapitulatif ──────────────────────────────────────── -->
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

                <div class="rounded-lg border bg-muted/30 p-3">
                    <p
                        class="text-xs font-medium tracking-wider text-muted-foreground uppercase"
                    >
                        Catégories
                    </p>
                    <p class="mt-1 text-sm font-semibold">
                        {{ baremesCommissionCategories.length }}
                    </p>
                </div>
            </div>

            <div
                v-for="cat in baremesCommissionCategories"
                :key="cat.categorie_id"
                class="overflow-hidden rounded-lg border"
            >
                <div class="border-b bg-muted/30 px-4 py-2.5">
                    <p
                        class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        {{ cat.categorie_nom }}
                    </p>
                    <p
                        v-if="hasProprietaire"
                        class="mt-0.5 text-xs font-medium text-primary"
                    >
                        Propriétaire : {{ formatGNF(cat.montant_proprietaire) }}
                        / unité
                    </p>
                    <p class="mt-0.5 text-xs font-medium text-muted-foreground">
                        Livraison : {{ formatGNF(cat.montant_livraison) }} /
                        unité
                    </p>
                </div>
                <table v-if="cat.montant_livraison > 0" class="w-full text-sm">
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
                                {{
                                    montantEstimeCategorie(cat.categorie_id, i)
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="px-4 py-2.5 text-xs text-muted-foreground">
                    Aucune répartition livreurs pour cette catégorie.
                </p>
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
