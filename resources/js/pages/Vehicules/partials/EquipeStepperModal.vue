<script setup lang="ts">
import CommissionMontantFixeEditor, {
    type CommissionMontantFixeMembre,
} from '@/components/commission/CommissionMontantFixeEditor.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { router } from '@inertiajs/vue3';
import {
    Check,
    ChevronLeft,
    ChevronRight,
    Info,
    Plus,
    Trash2,
    TriangleAlert,
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

/** Partage Livreur déjà enregistré, groupé par catégorie (montants GNF fixes). */
interface PartageCategorieExistant {
    categorie_id: string;
    parts: Array<{ livreur_id: string; montant_unitaire: number }>;
    /** Part du parrain du véhicule (null : aucune ligne parrain). */
    part_parrain?: number | null;
    /** PARRAIN-022 : part nulle déjà confirmée par une personne. */
    part_parrain_confirmee?: boolean;
}

interface EquipeExistante {
    id: string;
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
    // Résultat du contrôle live (verifierTelephone) pour la valeur ACTUELLE de telephone —
    // undefined : jamais vérifié depuis la dernière modification, null : vérifié sans conflit,
    // string : vérifié, message de conflit à afficher. Remis à undefined à chaque frappe
    // (onPhoneInput) pour ne jamais garder un verdict obsolète.
    _serverConflict?: string | null;
    _phoneChecking?: boolean;
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
    /**
     * Parrain du véhicule s'il est rémunéré sur le processus actif (ADR 0035) : sa part est
     * prise sur la même enveloppe et sa ligne est exigée (0 GNF accepté). Null sinon.
     */
    parrain?: { id: string; nom_complet: string } | null;
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
    // Les index changent après le splice : toute vérification en attente porterait sur la
    // mauvaise ligne, on annule tout plutôt que de tenter un réalignement.
    Object.values(phoneCheckTimers).forEach((t) => clearTimeout(t));
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

    // Le numéro a changé : le dernier verdict serveur (le cas échéant) ne s'applique plus.
    // _phoneChecking est aussi remis à false ici — une requête encore en vol pour l'ANCIENNE
    // valeur se sait obsolète (garde phoneAtCallTime dans checkTelephoneConflict) et ne le
    // remettra pas à true à sa résolution ; sans ce reset, raccourcir un numéro déjà à 9
    // chiffres pendant une vérification en cours bloquerait "Suivant" indéfiniment.
    membres.value[idx]._serverConflict = undefined;
    membres.value[idx]._phoneChecking = false;
    delete membres.value[idx]._errors.telephone;
    scheduleTelephoneCheck(idx);
}

function onPhoneBlur(idx: number) {
    clearTimeout(phoneCheckTimers[idx]);
    void checkTelephoneConflict(idx);
}

// ── Contrôle live du téléphone (avant "Suivant") ───────────────────────────
// Le conflit (numéro déjà affecté à un autre livreur/personne, cf.
// EquipeLivraisonController::detecterConflitTelephone()) ne doit plus être découvert
// seulement à la soumission finale (steps 2/3 déjà remplis) : on le vérifie au blur du
// champ, avec un filet de sécurité (goToStep2) pour tout numéro jamais encore vérifié.
const phoneCheckTimers: Record<number, ReturnType<typeof setTimeout>> = {};

function scheduleTelephoneCheck(idx: number) {
    clearTimeout(phoneCheckTimers[idx]);
    phoneCheckTimers[idx] = setTimeout(() => {
        void checkTelephoneConflict(idx);
    }, 600);
}

async function checkTelephoneConflict(idx: number): Promise<void> {
    const m = membres.value[idx];
    // Convoyeur sans numéro : rien à vérifier, le champ reste facultatif (cf.
    // EquipeLivraisonController::rules()). Format incomplet : le contrôle local suffit.
    if (!m || !/^\d{9}$/.test(m.telephone)) return;

    const phoneAtCallTime = m.telephone;
    membres.value[idx]._phoneChecking = true;

    try {
        const params = new URLSearchParams({
            telephone: `${GUINEA_PREFIX}${phoneAtCallTime}`,
        });
        if (m.livreur_id) params.set('livreur_id', m.livreur_id);
        if (props.equipe?.id) params.set('equipe_id', props.equipe.id);

        const res = await fetch(
            `/backoffice/equipes-livraison/verifier-telephone?${params.toString()}`,
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        );

        // Le numéro a changé pendant l'appel réseau : cette réponse est obsolète, on l'ignore
        // pour ne jamais afficher un verdict qui ne correspond plus à la valeur affichée.
        if (membres.value[idx]?.telephone !== phoneAtCallTime) return;

        if (res.ok) {
            const data = await res.json();
            membres.value[idx]._serverConflict = data.conflict
                ? ((data.message as string | null) ??
                  'Ce numéro est déjà utilisé.')
                : null;
            if (data.conflict) {
                membres.value[idx]._errors.telephone = membres.value[idx]
                    ._serverConflict as string;
            } else {
                delete membres.value[idx]._errors.telephone;
            }
        }
    } catch {
        // Vérification live indisponible (réseau) : le garde-fou serveur final (submit())
        // reste actif, jamais de blocage silencieux ici.
    } finally {
        if (membres.value[idx]?.telephone === phoneAtCallTime) {
            membres.value[idx]._phoneChecking = false;
        }
    }
}

const hasPendingPhoneCheck = computed(() =>
    membres.value.some((m) => m._phoneChecking),
);

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
        } else if (m._serverConflict) {
            // Conflit détecté par le contrôle live (checkTelephoneConflict) — doit lui aussi
            // bloquer le passage à l'étape 2, pas seulement s'afficher.
            m._errors.telephone = m._serverConflict;
            valid = false;
            phones.add(m.telephone);
        } else {
            phones.add(m.telephone);
        }
    });

    return valid && membres.value.length > 0;
}

async function goToStep2() {
    // Filet de sécurité : force la vérification de tout numéro complet jamais encore
    // contrôlé côté serveur (ex: l'utilisateur presse "Suivant" avant la fin du debounce
    // du blur) — "Suivant" ne doit jamais s'appuyer sur un numéro non vérifié (cf. incident
    // Sentry PHP-LARAVEL-66, réapparu en prod le 2026-09-02).
    await Promise.all(
        membres.value.map((m, i) =>
            /^\d{9}$/.test(m.telephone) && m._serverConflict === undefined
                ? checkTelephoneConflict(i)
                : Promise.resolve(),
        ),
    );

    if (!validateStep1()) return;
    markChanged();
    initPartagesParCategorie();
    step.value = 2;
}

// ── Étape 2 : Répartition des commissions PAR CATÉGORIE (livreurs et parrain), propriétaire hors partage ─
// Chaque catégorie ayant son propre barème Livreur (Paramètres →
// Commissions), sa répartition entre livreurs est elle aussi définie
// indépendamment, en montants GNF entiers fixes dont la somme doit égaler
// exactement le barème — plus aucun pourcentage (décision AMOA post-Phase 2,
// révisée suite à l'incident CMD-230826-004).

const partagesParCategorie = ref<Record<string, CommissionMontantFixeMembre[]>>(
    {},
);
/** Part du parrain par catégorie (seulement si le processus actif le rémunère). */
const partParrainParCategorie = ref<Record<string, number>>({});
/**
 * PARRAIN-022 : part du parrain laissée à 0 GNF confirmée explicitement (« aucune part pour le
 * moment »). Sans confirmation, le partage reste « À répartir » (ventes refusées après la date
 * d'activation de l'organisation).
 */
const confirmationPartParrainNulle = ref<Record<string, boolean>>({});

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
    const parrains: Record<string, number> = {};
    const confirmations: Record<string, boolean> = {};

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

        // Un seul membre pour cette catégorie et aucun parrain : toute l'enveloppe
        // lui revient automatiquement (rien à répartir). Avec un parrain, la
        // répartition reste une décision explicite (ADR 0035).
        if (
            !props.parrain &&
            parts.length === 1 &&
            parts[0].montant_unitaire === 0
        ) {
            parts[0].montant_unitaire = cat.montant_livraison;
        }

        result[cat.categorie_id] = parts;
        if (props.parrain) {
            parrains[cat.categorie_id] = existant?.part_parrain ?? 0;
            confirmations[cat.categorie_id] =
                (existant?.part_parrain ?? 0) === 0 &&
                existant?.part_parrain_confirmee === true;
        }
    }

    partagesParCategorie.value = result;
    partParrainParCategorie.value = parrains;
    confirmationPartParrainNulle.value = confirmations;
}

function onConfirmationPartParrainNulle(categorieId: string, valeur: boolean) {
    markChanged();
    confirmationPartParrainNulle.value = {
        ...confirmationPartParrainNulle.value,
        [categorieId]: valeur,
    };
}

function onPartParrainChange(categorieId: string, montant: number) {
    markChanged();
    partParrainParCategorie.value = {
        ...partParrainParCategorie.value,
        [categorieId]: montant,
    };
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

function totalPartageCategorie(categorieId: string): number {
    return (
        (partagesParCategorie.value[categorieId] ?? []).reduce(
            (total, part) => total + (part.montant_unitaire || 0),
            0,
        ) +
        (props.parrain ? (partParrainParCategorie.value[categorieId] ?? 0) : 0)
    );
}

function restePartageCategorie(categorieId: string, enveloppe: number): number {
    return enveloppe - totalPartageCategorie(categorieId);
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
        return (
            parts.length > 0 &&
            totalPartageCategorie(cat.categorie_id) === cat.montant_livraison
        );
    }),
);

/** Catégories encore déséquilibrées, nommées sous le bouton « Suivant ». */
const categoriesNonEquilibrees = computed(() =>
    categoriesAvecPartageLivraison.value
        .filter(
            (cat) =>
                restePartageCategorie(
                    cat.categorie_id,
                    cat.montant_livraison,
                ) !== 0,
        )
        .map((cat) => cat.categorie_nom),
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
        // l'équipe du propriétaire réel du véhicule. is_active non plus : l'état de l'équipe est
        // décidé par le serveur (activation d'un brouillon quand son partage devient conforme).
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
            ...(props.parrain
                ? {
                      part_parrain:
                          partParrainParCategorie.value[cat.categorie_id] ?? 0,
                      confirmer_part_parrain_nulle:
                          (partParrainParCategorie.value[cat.categorie_id] ??
                              0) === 0 &&
                          confirmationPartParrainNulle.value[
                              cat.categorie_id
                          ] === true,
                  }
                : {}),
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
                                  ? 'Répartition des commissions'
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
                    catégorie<template v-if="parrain">
                        entre l'équipe et le parrain du véhicule — sa part est
                        prise sur l'enveloppe, le total ne change pas</template
                    >. Les autres processus conservent leur propre répartition.
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
                                <span
                                    class="font-normal text-muted-foreground/70"
                                    >(obligatoire pour un chauffeur)</span
                                >
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
                                        @blur="onPhoneBlur(i)"
                                    />
                                </div>
                                <p
                                    v-if="m._phoneChecking"
                                    class="mt-1 text-xs text-muted-foreground"
                                >
                                    Vérification…
                                </p>
                                <p
                                    v-else-if="m._errors.telephone"
                                    class="mt-1 text-xs text-destructive"
                                    :data-testid="`telephone-error-${i}`"
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

        <!-- ── Étape 2 : Barèmes + partage PAR CATÉGORIE ─────────────────────── -->
        <div v-else-if="step === 2" class="space-y-4">
            <!-- Chaque catégorie a son propre barème Propriétaire ET Livraison
                 (Paramètres → Commissions) — jamais un montant global blended.
                 Le Propriétaire reste informatif ; la répartition (livreurs et,
                 s'il est rémunéré sur ce processus, parrain du véhicule) n'apparaît
                 que si Livraison > 0. Même éditeur que le transfert de véhicule. -->
            <div
                v-if="baremesCommissionCategories.length === 0"
                class="rounded-lg border border-dashed py-8 text-center text-sm text-muted-foreground"
            >
                Aucun barème de commission actif pour ce véhicule (Propriétaire
                et Livreur à 0 GNF ou non configurés dans Paramètres →
                Commissions).
            </div>

            <div
                v-for="cat in baremesCommissionCategories"
                :key="cat.categorie_id"
                class="rounded-lg border bg-background"
                :data-testid="`partage-categorie-${cat.categorie_id}`"
            >
                <div
                    class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b bg-muted/30 px-4 py-3"
                >
                    <p class="font-semibold text-foreground">
                        {{ cat.categorie_nom }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Barème Livreur :
                        <span class="font-semibold text-foreground tabular-nums"
                            >{{ formatGNF(cat.montant_livraison) }} / pack</span
                        >
                    </p>
                    <p
                        v-if="hasProprietaire"
                        class="w-full truncate text-xs text-muted-foreground"
                    >
                        Part propriétaire (information) :
                        {{ formatGNF(cat.montant_proprietaire) }} / pack —
                        {{ proprietaireNom }}
                    </p>
                </div>

                <div class="p-3">
                    <p
                        v-if="cat.montant_livraison <= 0"
                        class="py-1 text-xs text-muted-foreground"
                    >
                        Aucune répartition nécessaire
                    </p>
                    <CommissionMontantFixeEditor
                        v-else
                        :model-value="
                            partagesParCategorie[cat.categorie_id] ?? []
                        "
                        :enveloppe-unitaire="cat.montant_livraison"
                        :parrain="parrain ? { nom: parrain.nom_complet } : null"
                        :part-parrain="
                            partParrainParCategorie[cat.categorie_id] ?? 0
                        "
                        @update:model-value="
                            onPartageCategorieUpdate(cat.categorie_id, $event)
                        "
                        @update:part-parrain="
                            onPartParrainChange(cat.categorie_id, $event)
                        "
                    />
                    <label
                        v-if="
                            parrain &&
                            cat.montant_livraison > 0 &&
                            (partParrainParCategorie[cat.categorie_id] ?? 0) ===
                                0
                        "
                        class="mt-2 flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs dark:border-amber-900/50 dark:bg-amber-950/20"
                        :data-testid="`confirmer-part-parrain-nulle-${cat.categorie_id}`"
                    >
                        <Checkbox
                            :model-value="
                                confirmationPartParrainNulle[
                                    cat.categorie_id
                                ] === true
                            "
                            class="mt-0.5"
                            @update:model-value="
                                (v) =>
                                    onConfirmationPartParrainNulle(
                                        cat.categorie_id,
                                        v === true,
                                    )
                            "
                        />
                        <span>
                            Le parrain ne reçoit
                            <span class="font-semibold">aucune part</span>
                            pour le moment sur cette catégorie. Sans cette
                            confirmation, le partage reste « À répartir ».
                        </span>
                    </label>
                </div>
            </div>

            <p class="text-xs text-muted-foreground">
                La nouvelle répartition s'applique aux opérations à venir ; les
                commissions déjà calculées ne changent pas.
            </p>
            <p
                v-if="!partageValide && categoriesNonEquilibrees.length > 0"
                class="flex items-center gap-1.5 text-xs font-medium text-orange-600"
                data-testid="partage-categories-a-corriger"
            >
                <TriangleAlert class="h-3.5 w-3.5 shrink-0" />
                À équilibrer avant de continuer :
                {{ categoriesNonEquilibrees.join(', ') }}.
            </p>
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
                        <tr v-if="parrain" data-testid="recap-parrain">
                            <td class="px-4 py-2.5 font-medium">
                                Parrain — {{ parrain.nom_complet }}
                            </td>
                            <td
                                class="px-4 py-2.5 text-xs text-muted-foreground"
                            >
                                Parrain du véhicule
                            </td>
                            <td
                                class="px-4 py-2.5 text-right font-mono text-xs"
                            >
                                {{
                                    formatGNF(
                                        partParrainParCategorie[
                                            cat.categorie_id
                                        ] ?? 0,
                                    )
                                }}
                                / unité
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="px-4 py-2.5 text-xs text-muted-foreground">
                    Aucune répartition des commissions pour cette catégorie.
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
                    :disabled="membres.length === 0 || hasPendingPhoneCheck"
                    @click="goToStep2"
                >
                    <template v-if="hasPendingPhoneCheck">
                        Vérification…
                    </template>
                    <template v-else>
                        Suivant
                        <ChevronRight class="ml-1 h-4 w-4" />
                    </template>
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
