<script setup lang="ts">
import type { CommissionMontantFixeMembre } from '@/components/commission/CommissionMontantFixeEditor.vue';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/vue3';
import {
    Check,
    ChevronLeft,
    ChevronRight,
    CircleCheck,
    Info,
    Plus,
    Trash2,
    TriangleAlert,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
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

/** Partage Livreur déjà enregistré, groupé par catégorie (montants GNF fixes). */
interface PartageCategorieExistant {
    categorie_id: string;
    parts: Array<{ livreur_id: string; montant_unitaire: number }>;
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
    // Processus actif de la page (Vente/Distribution client/Transfert logistique) — le
    // partage saisi ici ne remplace jamais que CE processus (cf. syncPartagesCategorie()) ;
    // changer d'onglet recharge la page avec les barèmes/partages du nouveau processus.
    processusActif: string;
    processusOptions: { value: string; label: string }[];
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

        // Téléphone obligatoire pour un chauffeur ; facultatif pour un convoyeur
        // (cf. EquipeLivraisonController::rules() — incident Sentry PHP-LARAVEL-66).
        if (!m.telephone) {
            if (m.role === 'chauffeur') {
                m._errors.telephone = '9 chiffres requis';
                valid = false;
            }
        } else if (!/^\d{9}$/.test(m.telephone)) {
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

// ── Étape 2 : Répartition livreurs PAR CATÉGORIE, propriétaire hors partage ─
// Chaque catégorie ayant son propre barème Livreur (Paramètres →
// Commissions), sa répartition entre livreurs est elle aussi définie
// indépendamment, en montants GNF entiers fixes dont la somme doit égaler
// exactement le barème — plus aucun pourcentage (décision AMOA post-Phase 2,
// révisée suite à l'incident CMD-230826-004).

const partagesParCategorie = ref<Record<string, CommissionMontantFixeMembre[]>>(
    {},
);

function membreLabels(): string[] {
    const roleCounts: Record<string, number> = {};
    return membres.value.map((m) => {
        roleCounts[m.role] = (roleCounts[m.role] ?? 0) + 1;
        return membreLabel(m.role, roleCounts[m.role], m.nom_complet);
    });
}

function initPartagesParCategorie() {
    const labels = membreLabels();
    const result: Record<string, CommissionMontantFixeMembre[]> = {};

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
                montant_unitaire: partExistante?.montant_unitaire ?? 0,
            };
        });

        // Un seul membre pour cette catégorie : toute l'enveloppe lui revient
        // automatiquement (rien à répartir).
        if (parts.length === 1 && parts[0].montant_unitaire === 0) {
            parts[0].montant_unitaire = cat.montant_livraison;
        }

        result[cat.categorie_id] = parts;
    }

    partagesParCategorie.value = result;
}

function onPartageCategorieUpdate(
    categorieId: string,
    list: CommissionMontantFixeMembre[],
) {
    markChanged();
    partagesParCategorie.value = {
        ...partagesParCategorie.value,
        [categorieId]: list,
    };
}

function onMontantPartageChange(
    categorieId: string,
    membreId: string,
    montant: number | null,
) {
    const parts = partagesParCategorie.value[categorieId] ?? [];
    onPartageCategorieUpdate(
        categorieId,
        parts.map((part) =>
            part.id === membreId
                ? { ...part, montant_unitaire: montant ?? 0 }
                : part,
        ),
    );
}

function totalPartageCategorie(categorieId: string): number {
    return (partagesParCategorie.value[categorieId] ?? []).reduce(
        (total, part) => total + (part.montant_unitaire || 0),
        0,
    );
}

function restePartageCategorie(categorieId: string, enveloppe: number): number {
    return enveloppe - totalPartageCategorie(categorieId);
}

function etatPartageCategorie(
    categorieId: string,
    enveloppe: number,
): 'reste' | 'depassement' | 'complet' {
    const reste = restePartageCategorie(categorieId, enveloppe);
    if (reste > 0) return 'reste';
    if (reste < 0) return 'depassement';
    return 'complet';
}

// ── Partage : validité ───────────────────────────────────────────────────

// CHAQUE catégorie ayant un barème Livreur > 0 doit voir sa somme égaler
// exactement l'enveloppe (reste à attribuer = 0, aucune tolérance) — aucune
// n'est facultative, jamais une répartition égale déduite pour celle qu'on
// aurait oublié de configurer. Les catégories où seul le Propriétaire a un
// barème positif sont exclues : rien à répartir entre livreurs pour elles.
// Si la liste est vide, il n'y a simplement rien à répartir — équipe valide.
const partageValide = computed(() =>
    categoriesAvecPartageLivraison.value.every((cat) => {
        const parts = partagesParCategorie.value[cat.categorie_id] ?? [];
        const total = parts.reduce((s, p) => s + (p.montant_unitaire || 0), 0);
        return parts.length > 0 && total === cat.montant_livraison;
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
    if (!d) return '—';
    return `+224 ${d.slice(0, 3)} ${d.slice(3, 5)} ${d.slice(5, 7)} ${d.slice(7)}`;
}

/** Montant fixe par unité attribué à un membre pour une catégorie. */
function montantUnitaireMembre(
    categorieId: string,
    membreIndex: number,
): string {
    const part = partagesParCategorie.value[categorieId]?.[membreIndex];
    if (!part) return '—';

    return `${formatGNF(part.montant_unitaire)} / unité`;
}

// ── Soumission ──────────────────────────────────────────────────────────────

function buildPayload() {
    const base = {
        vehicule_id: props.vehicule.id,
        // proprietaire_id n'est jamais envoyé : toujours dérivé côté serveur depuis
        // Vehicule::proprietaire_id (cf. EquipeLivraisonController), pour ne jamais désynchroniser
        // l'équipe du propriétaire réel du véhicule.
        is_active: props.equipe?.is_active ?? true,
        // Le partage saisi ne remplace que CE processus — jamais un fallback implicite vers
        // vente (cf. EquipeLivraisonController::syncPartagesCategorie()).
        processus_code: props.processusActif,
    };

    return {
        ...base,
        membres: membres.value.map((m, i) => ({
            livreur_id: m.livreur_id ?? null,
            nom_complet: m.nom_complet.trim() || null,
            telephone: m.telephone ? `${GUINEA_PREFIX}${m.telephone}` : null,
            role: m.role,
            ordre: i,
        })),
        partages_categorie: categoriesAvecPartageLivraison.value.map((cat) => ({
            categorie_id: cat.categorie_id,
            parts: (partagesParCategorie.value[cat.categorie_id] ?? []).map(
                (p) => ({
                    membre_ordre: parseInt(p.id.replace('membre-', ''), 10),
                    montant_unitaire: p.montant_unitaire,
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
        :style="{ width: 'min(1080px, 96vw)' }"
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
                                  ? 'Répartition livreurs'
                                  : 'Récapitulatif'
                        }}
                    </span>
                </div>
                <div v-if="n < 3" class="h-px flex-1 bg-border" />
            </template>
        </div>

        <div
            v-if="step === 2"
            class="mb-4 flex items-start gap-3 rounded-lg border border-primary/15 bg-primary/5 px-4 py-3"
        >
            <Info class="mt-0.5 h-4 w-4 shrink-0 text-primary" />
            <div>
                <p class="text-sm font-semibold text-foreground">
                    Répartition —
                    {{
                        processusOptions.find((o) => o.value === processusActif)
                            ?.label ?? processusActif
                    }}
                </p>
                <p class="mt-0.5 text-xs leading-5 text-muted-foreground">
                    Attribuez entièrement l'enveloppe Livreur de chaque
                    catégorie. Les autres processus conservent leur propre
                    répartition.
                </p>
            </div>
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
                            <th class="w-52 px-3 py-2.5">
                                Téléphone
                                <span class="font-normal text-muted-foreground/70">(obligatoire pour un chauffeur)</span>
                            </th>
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
                                        :placeholder="
                                            m.role === 'convoyeur'
                                                ? '9 chiffres (optionnel)'
                                                : '9 chiffres'
                                        "
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
        <div v-else-if="step === 2">
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
                et Livreur à 0 GNF ou non configurés dans Paramètres →
                Commissions).
            </div>

            <div v-else class="overflow-x-auto rounded-lg border bg-background">
                <table class="w-full min-w-[760px] text-sm">
                    <thead>
                        <tr
                            class="border-b bg-muted/40 text-left text-xs font-medium text-muted-foreground"
                        >
                            <th class="w-[18%] px-4 py-3">Catégorie</th>
                            <th class="w-[22%] px-4 py-3">Part propriétaire</th>
                            <th class="w-[38%] px-4 py-3">
                                Répartition par membre
                            </th>
                            <th class="w-[22%] px-4 py-3">Contrôle</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="cat in baremesCommissionCategories"
                            :key="cat.categorie_id"
                            class="align-top"
                        >
                            <td class="px-4 py-4">
                                <p class="font-semibold text-foreground">
                                    {{ cat.categorie_nom }}
                                </p>
                            </td>

                            <td class="px-4 py-4">
                                <template v-if="hasProprietaire">
                                    <p class="font-semibold tabular-nums">
                                        {{
                                            formatGNF(cat.montant_proprietaire)
                                        }}
                                        / unité
                                    </p>
                                    <p
                                        class="mt-1 truncate text-xs text-muted-foreground"
                                    >
                                        {{ proprietaireNom }}
                                    </p>
                                </template>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>

                            <td class="px-4 py-3">
                                <p
                                    v-if="cat.montant_livraison <= 0"
                                    class="py-1 text-xs text-muted-foreground"
                                >
                                    Aucune répartition nécessaire
                                </p>
                                <div v-else class="space-y-2">
                                    <div
                                        v-for="m in partagesParCategorie[
                                            cat.categorie_id
                                        ] ?? []"
                                        :key="m.id"
                                        class="flex items-center gap-2"
                                    >
                                        <span
                                            class="min-w-0 flex-1 truncate text-xs font-medium"
                                            :title="m.label"
                                        >
                                            {{ m.label }}
                                        </span>
                                        <InputNumber
                                            :model-value="
                                                m.montant_unitaire || null
                                            "
                                            placeholder="0"
                                            :min="0"
                                            :max-fraction-digits="0"
                                            suffix=" GNF"
                                            class="w-36 shrink-0"
                                            :data-testid="`partage-livreur-montant-${m.id}`"
                                            :input-style="{
                                                textAlign: 'right',
                                                width: '100%',
                                                fontWeight: '600',
                                            }"
                                            @update:model-value="
                                                onMontantPartageChange(
                                                    cat.categorie_id,
                                                    m.id,
                                                    $event,
                                                )
                                            "
                                        />
                                    </div>
                                </div>
                            </td>

                            <td class="px-4 py-4">
                                <template v-if="cat.montant_livraison > 0">
                                    <p class="text-xs text-muted-foreground">
                                        Attribué
                                    </p>
                                    <p
                                        class="mt-0.5 font-semibold tabular-nums"
                                    >
                                        {{
                                            formatGNF(
                                                totalPartageCategorie(
                                                    cat.categorie_id,
                                                ),
                                            )
                                        }}
                                        <span
                                            class="font-normal text-muted-foreground"
                                        >
                                            /
                                            {{
                                                formatGNF(cat.montant_livraison)
                                            }}
                                        </span>
                                    </p>
                                    <p
                                        class="mt-2 flex items-center gap-1.5 text-xs font-semibold"
                                        :class="{
                                            'text-emerald-600':
                                                etatPartageCategorie(
                                                    cat.categorie_id,
                                                    cat.montant_livraison,
                                                ) === 'complet',
                                            'text-orange-600':
                                                etatPartageCategorie(
                                                    cat.categorie_id,
                                                    cat.montant_livraison,
                                                ) === 'reste',
                                            'text-destructive':
                                                etatPartageCategorie(
                                                    cat.categorie_id,
                                                    cat.montant_livraison,
                                                ) === 'depassement',
                                        }"
                                        data-testid="partage-livreur-etat"
                                    >
                                        <CircleCheck
                                            v-if="
                                                etatPartageCategorie(
                                                    cat.categorie_id,
                                                    cat.montant_livraison,
                                                ) === 'complet'
                                            "
                                            class="h-4 w-4 shrink-0"
                                        />
                                        <TriangleAlert
                                            v-else
                                            class="h-4 w-4 shrink-0"
                                        />
                                        <span>
                                            {{
                                                etatPartageCategorie(
                                                    cat.categorie_id,
                                                    cat.montant_livraison,
                                                ) === 'complet'
                                                    ? 'Répartition complète'
                                                    : etatPartageCategorie(
                                                            cat.categorie_id,
                                                            cat.montant_livraison,
                                                        ) === 'reste'
                                                      ? `Reste ${formatGNF(restePartageCategorie(cat.categorie_id, cat.montant_livraison))}`
                                                      : `Dépassement ${formatGNF(Math.abs(restePartageCategorie(cat.categorie_id, cat.montant_livraison)))}`
                                            }}
                                        </span>
                                    </p>
                                </template>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
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
                        Livreur : {{ formatGNF(cat.montant_livraison) }} / unité
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
                                Montant fixe
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
                                {{ montantUnitaireMembre(cat.categorie_id, i) }}
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
