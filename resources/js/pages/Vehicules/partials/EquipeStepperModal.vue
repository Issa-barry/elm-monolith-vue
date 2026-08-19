<script setup lang="ts">
import CommissionShareEditor, {
    type CommissionShareMembre,
} from '@/components/commission/CommissionShareEditor.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
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

/** V2 uniquement — partage Livraison déjà enregistré, groupé par catégorie. */
interface PartageCategorieExistant {
    categorie_id: string;
    parts: Array<{ livreur_id: string; part_pourcentage: number }>;
}

interface EquipeExistante {
    id: string;
    is_active: boolean;
    commission_unitaire_par_pack: number;
    montant_par_pack_proprietaire: number | null;
    taux_commission_proprietaire: number | null;
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

/** Legacy uniquement — ligne du tableau montant/% (propriétaire + membres). */
interface LignePartage {
    id: string;
    label: string;
    montant: number;
    taux: number;
}

interface BaremeCommission {
    proprietaire: number | null;
    livraison: number | null;
}

/** V2 uniquement — barème Livraison résolu pour une catégorie (cf. conception
 * cible : les barèmes Livraison varient par catégorie, donc le partage entre
 * livreurs est lui aussi défini par catégorie, jamais un seul pourcentage
 * valable pour toute la commande). */
interface BaremeLivraisonCategorie {
    categorie_id: string;
    categorie_nom: string;
    montant: number;
}

const props = defineProps<{
    visible: boolean;
    vehicule: VehiculeInfo;
    equipe: EquipeExistante | null;
    proprietaires: ProprietaireOption[];
    moteurCommission: 'legacy' | 'v2';
    baremeCommission: BaremeCommission;
    baremesLivraisonCategories: BaremeLivraisonCategorie[];
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
const commission = ref(0); // legacy uniquement
const montantProp = ref(0); // legacy uniquement
const lignes = ref<LignePartage[]>([]); // legacy uniquement

// ── Computed ────────────────────────────────────────────────────────────────

const isLegacy = computed(() => props.moteurCommission === 'legacy');

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

// V2 uniquement — commission propriétaire : montant fixe du barème (Paramètres →
// Commissions), jamais saisi ici : le propriétaire ne participe plus au partage
// des livreurs (décision AMOA #1 — deux enveloppes distinctes, plus un pot commun).
const montantProprietaire = computed(() => props.baremeCommission.proprietaire);
// V2 uniquement — montant Livraison : sert de référence pour la saisie Montant/%
// des livreurs, le montant réel dépend de la catégorie vendue à chaque vente
// (cf. conception cible §0.2.2/§0.3).
const montantLivraison = computed(() => props.baremeCommission.livraison);

// V2 uniquement — poids informatif du propriétaire dans le total des deux
// barèmes affichés — dénominateur différent de la part des livreurs dans
// l'enveloppe Livraison (cf. conception cible §0.3.2, ne jamais confondre les
// deux pourcentages).
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
                montant_par_pack: m.montant_par_pack,
                ordre: m.ordre,
                _errors: {},
            }));
            commission.value = props.equipe.commission_unitaire_par_pack;
            montantProp.value = props.equipe.montant_par_pack_proprietaire ?? 0;
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
            commission.value = 950;
            montantProp.value = 0;
        }
    },
);

// ── Étape 1 : Membres (partagée legacy/V2) ─────────────────────────────────

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
    if (isLegacy.value) {
        buildLignes();
        if (commission.value <= 0) commission.value = 950;
    } else {
        initPartagesParCategorie();
    }
    step.value = 2;
}

// ── Étape 2 (legacy) : Partage montant/% incluant le propriétaire ──────────

function toTaux(montant: number, comm: number): number {
    if (!comm || comm <= 0) return 0;
    return parseFloat(((montant / comm) * 100).toFixed(2));
}

function toMontant(taux: number, comm: number): number {
    return Math.round((taux / 100) * comm);
}

// Bénéficiaires dont le montant/taux a été saisi explicitement par l'utilisateur pendant cette
// session d'édition — permet de compléter automatiquement le DERNIER bénéficiaire restant
// (montant = commission - somme des autres) sans jamais inventer une répartition entre
// plusieurs bénéficiaires encore non saisis (cf. spécification "auto-calcul du partage").
const touchedIds = ref<Set<string>>(new Set());

function buildLignes() {
    touchedIds.value = new Set();
    const comm = commission.value > 0 ? commission.value : 950;
    const newLignes: LignePartage[] = [];

    if (hasProprietaire.value) {
        newLignes.push({
            id: 'proprietaire',
            label: `Propriétaire — ${proprietaireNom.value ?? '—'}`,
            montant: montantProp.value,
            taux: toTaux(montantProp.value, comm),
        });
    }

    const roleCounts: Record<string, number> = {};
    membres.value.forEach((m, i) => {
        roleCounts[m.role] = (roleCounts[m.role] ?? 0) + 1;
        newLignes.push({
            id: `membre-${i}`,
            label: membreLabel(m.role, roleCounts[m.role], m.nom_complet),
            montant: m.montant_par_pack,
            taux: toTaux(m.montant_par_pack, comm),
        });
    });

    lignes.value = newLignes;
}

/**
 * Complète automatiquement le seul bénéficiaire restant non saisi avec le reliquat
 * (commission - somme des bénéficiaires déjà saisis) — jamais s'il reste 2+ bénéficiaires non
 * saisis (répartition ambiguë) et jamais si le reliquat est négatif (dépassement, laissé tel
 * quel pour que la validation normale signale l'erreur).
 */
function recomputeAutoFill(editedId: string) {
    touchedIds.value.add(editedId);

    if (lignes.value.length < 2) return;

    const untouched = lignes.value.filter((l) => !touchedIds.value.has(l.id));
    if (untouched.length !== 1) return;

    const cible = untouched[0];
    const sommeAutres = lignes.value
        .filter((l) => l.id !== cible.id)
        .reduce((s, l) => s + (l.montant || 0), 0);
    const reste = commission.value - sommeAutres;
    if (reste < 0) return;

    cible.montant = Math.round(reste);
    cible.taux = toTaux(cible.montant, commission.value);
}

watch(commission, (newComm) => {
    if (!isLegacy.value) return;

    lignes.value.forEach((l) => {
        l.taux = toTaux(l.montant, newComm);
    });

    if (touchedIds.value.size === 0) return;
    const untouched = lignes.value.filter((l) => !touchedIds.value.has(l.id));
    if (untouched.length !== 1 || lignes.value.length < 2) return;

    const cible = untouched[0];
    const sommeAutres = lignes.value
        .filter((l) => l.id !== cible.id)
        .reduce((s, l) => s + (l.montant || 0), 0);
    const reste = newComm - sommeAutres;
    if (reste < 0) return;

    cible.montant = Math.round(reste);
    cible.taux = toTaux(cible.montant, newComm);
});

function onMontantChange(ligne: LignePartage, val: number | null) {
    const nouveauMontant = val ?? 0;
    // PrimeVue InputNumber ré-émet sa valeur courante au montage (écho, pas une saisie) —
    // sans ce garde-fou, cet écho marquait la ligne comme "touchée" avant toute interaction
    // réelle et empêchait recomputeAutoFill de jamais la considérer comme le seul bénéficiaire
    // restant à compléter automatiquement.
    if (nouveauMontant === ligne.montant) return;

    markChanged();
    ligne.montant = nouveauMontant;
    ligne.taux = toTaux(ligne.montant, commission.value);
    recomputeAutoFill(ligne.id);
}

function onTauxChange(ligne: LignePartage, val: number | null) {
    const nouveauTaux = val ?? 0;
    if (nouveauTaux === ligne.taux) return;

    markChanged();
    ligne.taux = nouveauTaux;
    ligne.montant = toMontant(ligne.taux, commission.value);
    recomputeAutoFill(ligne.id);
}

function applyPartageToMembres() {
    membres.value = membres.value.map((m, i) => {
        const ligne = lignes.value.find((l) => l.id === `membre-${i}`);
        return { ...m, montant_par_pack: ligne?.montant ?? m.montant_par_pack };
    });
    if (hasProprietaire.value) {
        const propLigne = lignes.value.find((l) => l.id === 'proprietaire');
        montantProp.value = propLigne?.montant ?? 0;
    }
}

// ── Étape 2 (V2) : Partage livreurs PAR CATÉGORIE, propriétaire hors partage ─
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

    for (const cat of props.baremesLivraisonCategories) {
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

// ── Partage : total/validité (branché selon le moteur) ─────────────────────

const totalPartage = computed(() => {
    // Legacy uniquement — total unique, propriétaire inclus.
    return lignes.value.reduce((s, l) => s + (l.montant || 0), 0);
});

const resteARepartir = computed(() => commission.value - totalPartage.value);

const partageValide = computed(() => {
    if (isLegacy.value) {
        return (
            commission.value > 0 &&
            Math.abs(totalPartage.value - commission.value) < 0.01
        );
    }
    // V2 : CHAQUE catégorie ayant un barème Livraison doit totaliser 100 % —
    // aucune n'est facultative, jamais une répartition égale déduite pour
    // celle qu'on aurait oublié de configurer.
    if (props.baremesLivraisonCategories.length === 0) return false;
    return props.baremesLivraisonCategories.every((cat) => {
        const parts = partagesParCategorie.value[cat.categorie_id] ?? [];
        const total = parts.reduce((s, p) => s + (p.part_pourcentage || 0), 0);
        return parts.length > 0 && Math.abs(total - 100) < 0.01;
    });
});

function goToStep3() {
    if (!partageValide.value) return;
    if (isLegacy.value) applyPartageToMembres();
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

/** V2 uniquement — montant estimé d'un membre pour une catégorie = barème
 * Livraison de cette catégorie × sa part. */
function montantEstimeCategorie(
    categorieId: string,
    membreIndex: number,
): string {
    const cat = props.baremesLivraisonCategories.find(
        (c) => c.categorie_id === categorieId,
    );
    const part = partagesParCategorie.value[categorieId]?.[membreIndex];
    if (!cat || !part) return '—';

    const montant = Math.round((part.part_pourcentage / 100) * cat.montant);
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

    if (isLegacy.value) {
        return {
            ...base,
            commission_unitaire_par_pack: commission.value,
            montant_par_pack_proprietaire: hasProprietaire.value
                ? montantProp.value
                : null,
            membres: membres.value.map((m, i) => ({
                livreur_id: m.livreur_id ?? null,
                nom_complet: m.nom_complet.trim() || null,
                telephone: `${GUINEA_PREFIX}${m.telephone}`,
                role: m.role,
                montant_par_pack: m.montant_par_pack,
                ordre: i,
            })),
        };
    }

    return {
        ...base,
        membres: membres.value.map((m, i) => ({
            livreur_id: m.livreur_id ?? null,
            nom_complet: m.nom_complet.trim() || null,
            telephone: `${GUINEA_PREFIX}${m.telephone}`,
            role: m.role,
            ordre: i,
        })),
        partages_categorie: props.baremesLivraisonCategories.map((cat) => ({
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

        <!-- ── Étape 1 : Membres (partagée legacy/V2) ──────────────────────── -->
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

        <!-- ── Étape 2 (LEGACY) : Partage montant/% incluant le propriétaire ── -->
        <div v-else-if="step === 2 && isLegacy" class="space-y-5">
            <div>
                <Label
                    for="step-commission"
                    class="mb-1.5 block text-xs font-medium"
                >
                    Commission unitaire par pack (GNF)
                    <span class="text-destructive">*</span>
                </Label>
                <InputNumber
                    :model-value="commission || null"
                    placeholder="0"
                    input-id="step-commission"
                    :min="1"
                    :max-fraction-digits="0"
                    suffix=" GNF"
                    class="w-full"
                    :input-style="{ textAlign: 'right', width: '100%' }"
                    @update:model-value="commission = $event ?? 0"
                />
                <p class="mt-1 text-xs text-muted-foreground">
                    Montant total à répartir entre tous les bénéficiaires.
                </p>
            </div>

            <div
                v-if="lignes.length > 0"
                class="overflow-hidden rounded-lg border"
            >
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40">
                            <th
                                class="px-3 py-2.5 text-left text-xs font-medium text-muted-foreground"
                            >
                                Bénéficiaire
                            </th>
                            <th
                                class="px-3 py-2.5 text-right text-xs font-medium text-muted-foreground"
                            >
                                Montant (GNF)
                            </th>
                            <th
                                class="w-32 px-3 py-2.5 text-right text-xs font-medium text-muted-foreground"
                            >
                                %
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="ligne in lignes"
                            :key="ligne.id"
                            class="border-b last:border-b-0"
                            :class="
                                ligne.id === 'proprietaire'
                                    ? 'bg-primary/5'
                                    : ''
                            "
                        >
                            <td class="px-3 py-2 text-sm">
                                <template v-if="ligne.id === 'proprietaire'">
                                    <span
                                        class="mr-1.5 inline-flex items-center rounded-full bg-primary px-2 py-0.5 text-[10px] font-semibold tracking-wide text-primary-foreground uppercase"
                                        >Propriétaire</span
                                    >
                                    <span class="font-medium text-primary">{{
                                        ligne.label.replace(
                                            'Propriétaire — ',
                                            '',
                                        )
                                    }}</span>
                                    <span
                                        v-if="
                                            vehicule.proprietaire_est_entreprise
                                        "
                                        class="ml-1.5 inline-flex items-center rounded-full bg-indigo-50 px-1.5 py-0.5 text-[10px] font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300"
                                        >Entreprise</span
                                    >
                                </template>
                                <template v-else>{{ ligne.label }}</template>
                            </td>
                            <td class="px-3 py-2">
                                <InputNumber
                                    :model-value="ligne.montant || null"
                                    placeholder="0"
                                    :min="0"
                                    :max="commission"
                                    :max-fraction-digits="0"
                                    class="w-full"
                                    :input-style="{
                                        textAlign: 'right',
                                        width: '100%',
                                    }"
                                    @update:model-value="
                                        onMontantChange(ligne, $event)
                                    "
                                />
                            </td>
                            <td class="px-3 py-2">
                                <InputNumber
                                    :model-value="ligne.taux || null"
                                    placeholder="0 %"
                                    :min="0"
                                    :max="100"
                                    :max-fraction-digits="2"
                                    suffix=" %"
                                    :disabled="!commission || commission <= 0"
                                    class="w-full"
                                    :input-style="{
                                        textAlign: 'right',
                                        width: '100%',
                                    }"
                                    @update:model-value="
                                        onTauxChange(ligne, $event)
                                    "
                                />
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t bg-muted/20">
                            <td class="px-3 py-2.5 text-sm font-semibold">
                                Total
                            </td>
                            <td
                                class="px-3 py-2.5 text-right font-mono text-sm font-semibold"
                                :class="
                                    partageValide
                                        ? 'text-emerald-600'
                                        : 'text-destructive'
                                "
                            >
                                {{ totalPartage }} GNF
                            </td>
                            <td
                                class="px-3 py-2.5 text-right font-mono text-xs"
                            >
                                <span
                                    v-if="partageValide"
                                    class="font-semibold text-emerald-600"
                                    >✓ 100 %</span
                                >
                                <span v-else class="text-destructive"
                                    >≠ {{ commission }} GNF</span
                                >
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p
                v-if="!partageValide && lignes.length > 0 && commission > 0"
                class="text-xs text-destructive"
            >
                La somme ({{ totalPartage }} GNF) doit être égale à la
                commission ({{ commission }} GNF).
                {{
                    resteARepartir > 0
                        ? `Reste à répartir : ${resteARepartir} GNF.`
                        : `Dépassement : ${Math.abs(resteARepartir)} GNF.`
                }}
            </p>
        </div>

        <!-- ── Étape 2 (V2) : Partage livreurs PAR CATÉGORIE ───────────────── -->
        <div v-else-if="step === 2" class="space-y-5">
            <!-- Propriétaire — montant indicatif, jamais éditable ici (issu du
                 barème global Paramètres → Commissions, hors partage) -->
            <div
                v-if="hasProprietaire"
                class="rounded-lg border bg-primary/5 p-3"
            >
                <p
                    class="text-xs font-medium tracking-wider text-muted-foreground uppercase"
                >
                    Propriétaire — {{ proprietaireNom }}
                </p>
                <p class="mt-1 text-sm font-semibold text-primary">
                    {{ formatGNF(montantProprietaire) }}
                    <span
                        v-if="partProprietaireDuBareme !== null"
                        class="font-normal text-muted-foreground"
                    >
                        • {{ partProprietaireDuBareme }} %</span
                    >
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Hors partage — vient du barème Propriétaire, jamais
                    modifiable ici.
                </p>
            </div>

            <div
                v-if="baremesLivraisonCategories.length === 0"
                class="rounded-lg border border-dashed py-8 text-center text-sm text-muted-foreground"
            >
                Aucun barème Livraison configuré. Définissez-en un dans
                Paramètres → Commissions avant de configurer le partage.
            </div>

            <div
                v-for="cat in baremesLivraisonCategories"
                :key="cat.categorie_id"
                class="space-y-2"
            >
                <div class="rounded-lg border bg-muted/30 p-3">
                    <p
                        class="text-xs font-medium tracking-wider text-muted-foreground uppercase"
                    >
                        {{ cat.categorie_nom }}
                    </p>
                    <p class="mt-1 text-sm font-semibold">
                        {{ formatGNF(cat.montant) }}
                        <span class="font-normal text-muted-foreground"
                            >/ unité — Livraison</span
                        >
                    </p>
                </div>

                <CommissionShareEditor
                    :model-value="partagesParCategorie[cat.categorie_id] ?? []"
                    :enveloppe-montant="cat.montant"
                    @update:model-value="
                        (list) =>
                            onPartageCategorieUpdate(cat.categorie_id, list)
                    "
                />
            </div>
        </div>

        <!-- ── Étape 3 (LEGACY) : Récapitulatif ────────────────────────────── -->
        <div v-else-if="step === 3 && isLegacy" class="space-y-4">
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
                        Part : {{ formatGNF(montantProp) }}
                    </p>
                </div>

                <div class="rounded-lg border bg-muted/30 p-3">
                    <p
                        class="text-xs font-medium tracking-wider text-muted-foreground uppercase"
                    >
                        Commission / pack
                    </p>
                    <p class="mt-1 text-sm font-semibold">
                        {{ formatGNF(commission) }}
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
                                {{ formatGNF(m.montant_par_pack) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── Étape 3 (V2) : Récapitulatif ────────────────────────────────── -->
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
                        Catégories Livraison
                    </p>
                    <p class="mt-1 text-sm font-semibold">
                        {{ baremesLivraisonCategories.length }}
                    </p>
                </div>
            </div>

            <div
                v-for="cat in baremesLivraisonCategories"
                :key="cat.categorie_id"
                class="overflow-hidden rounded-lg border"
            >
                <div
                    class="flex items-center justify-between border-b bg-muted/30 px-4 py-2.5"
                >
                    <p
                        class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        {{ cat.categorie_nom }}
                    </p>
                    <p class="text-xs font-medium text-muted-foreground">
                        {{ formatGNF(cat.montant) }} / unité
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
                                {{
                                    montantEstimeCategorie(cat.categorie_id, i)
                                }}
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
