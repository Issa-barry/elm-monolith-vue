import { formatGNF } from '@/lib/utils';
import { Landmark, Smartphone, UserRound, Wallet } from 'lucide-vue-next';
import type { Component } from 'vue';

export type Nature = 'agence' | 'dediee';

/**
 * Cycle de vie d'un support (dérivé côté serveur de sa validation et de son activation) :
 * un support est créé en brouillon, inutilisable, puis validé — il devient alors actif — et peut
 * ensuite être désactivé.
 */
export type StatutSupport = 'brouillon' | 'actif' | 'inactif';

export interface CompteTresorerie {
    id: string;
    site: string | null;
    site_id: string;
    type: string;
    type_label: string;
    libelle: string;
    nature: Nature;
    agent: { id: string; nom: string } | null;
    compte_comptable_id: string;
    compte_numero: string | null;
    moyen_paiement_defaut: string | null;
    actif: boolean;
    statut: StatutSupport;
    statut_label: string;
    valide_le: string | null;
    valide_par: string | null;
    /** Permission, agence et état (brouillon) vérifiés côté serveur. */
    peut_valider: boolean;
    solde: number;
    /**
     * Versements envoyés par cette caisse, pas encore reçus par la caisse de l'agence : argent déjà
     * sorti de `solde` (transit au grand livre) mais pas encore crédité ailleurs. Information de
     * suivi — jamais à additionner au solde ni à présenter comme disponible.
     */
    en_cours_versement: number;
    versements_en_cours: number;
    peut_verser: boolean;
    solde_ouverture: { id: string; montant: number; statut: string } | null;
}

export interface NatureAffichee {
    label: string;
    titre: string;
    icone: Component;
    variante: 'secondary' | 'outline';
}

/** Nature affichée en badge : la caisse dédiée à un agent d'abord, sinon le type du support d'agence. */
export function natureAffichee(
    c: Pick<CompteTresorerie, 'nature' | 'type' | 'type_label'>,
): NatureAffichee {
    if (c.nature === 'dediee') {
        return {
            label: 'Caisse dédiée',
            titre: 'Caisse dédiée à un agent',
            icone: UserRound,
            variante: 'secondary',
        };
    }

    switch (c.type) {
        case 'banque':
            return {
                label: 'Banque',
                titre: "Compte bancaire de l'agence",
                icone: Landmark,
                variante: 'outline',
            };
        case 'mobile_money':
            return {
                label: 'Mobile Money',
                titre: "Compte Mobile Money de l'agence",
                icone: Smartphone,
                variante: 'outline',
            };
        case 'caisse':
            return {
                label: 'Caisse agence',
                titre: "Caisse de l'agence",
                icone: Wallet,
                variante: 'outline',
            };
        default:
            return {
                label: c.type_label,
                titre: c.type_label,
                icone: Wallet,
                variante: 'outline',
            };
    }
}

export type ActionSupport =
    | 'modifier'
    | 'saisir_solde_ouverture'
    | 'valider_solde_ouverture'
    | 'desactiver'
    | 'reactiver';

/**
 * Actions du menu ⋮ d'une ligne. Elles reprennent exactement les conditions déjà en vigueur sur
 * la page (gestion des supports, support d'agence pour le solde d'ouverture) : aucune action
 * n'est proposée sans la permission de l'exécuter. « Verser à l'agence » et « Valider » restent
 * hors du menu : ce sont les actions principales d'une ligne, pilotées par `peut_verser` et
 * `peut_valider` (permission, portée et état calculés côté serveur).
 *
 * Un brouillon est inutilisable : seule sa modification est proposée (ni solde d'ouverture, ni
 * désactivation) ; il ne devient actif que par sa validation, jamais par « Réactiver ».
 */
export function actionsMenu(
    c: Pick<CompteTresorerie, 'nature' | 'statut' | 'solde_ouverture'>,
    peutGerer: boolean,
): ActionSupport[] {
    if (!peutGerer) {
        return [];
    }

    const actions: ActionSupport[] = ['modifier'];

    if (c.statut === 'brouillon') {
        return actions;
    }

    if (c.nature === 'agence') {
        if (!c.solde_ouverture) {
            actions.push('saisir_solde_ouverture');
        } else if (c.solde_ouverture.statut === 'brouillon') {
            actions.push('valider_solde_ouverture');
        }
    }

    actions.push(c.statut === 'actif' ? 'desactiver' : 'reactiver');

    return actions;
}

/**
 * Alerte d'un support ACTIF d'agence dont le solde d'ouverture demande une action — sinon rien :
 * un solde d'ouverture validé n'est pas une information à afficher en permanence dans la liste
 * (son détail est dans « Modifier le support »). Un brouillon ou un support désactivé n'entre pas
 * dans la position de trésorerie : aucune action attendue sur son solde d'ouverture.
 */
export function alerteSoldeOuverture(
    c: Pick<CompteTresorerie, 'nature' | 'statut' | 'solde_ouverture'>,
): string | null {
    if (c.nature !== 'agence' || c.statut !== 'actif') {
        return null;
    }

    if (!c.solde_ouverture) {
        return "Solde d'ouverture à saisir";
    }

    if (c.solde_ouverture.statut === 'brouillon') {
        return `Solde d'ouverture à valider · ${formatGNF(c.solde_ouverture.montant)} · non compté dans le solde`;
    }

    return null;
}

export interface ResumeSupports {
    total: number;
    caissesAgents: number;
    soldeTotal: number;
    enCoursVersement: number;
    versementsEnCours: number;
}

/**
 * Synthèse de la liste affichée : simple lecture des lignes déjà calculées par le serveur. Le
 * montant « en cours de versement » reste distinct du solde total : Solde ≠ en cours ≠ reçu.
 */
export function resumeSupports(
    comptes: Pick<
        CompteTresorerie,
        'nature' | 'solde' | 'en_cours_versement' | 'versements_en_cours'
    >[],
): ResumeSupports {
    return {
        total: comptes.length,
        caissesAgents: comptes.filter((c) => c.nature === 'dediee').length,
        soldeTotal: comptes.reduce((somme, c) => somme + c.solde, 0),
        enCoursVersement: comptes.reduce(
            (somme, c) => somme + c.en_cours_versement,
            0,
        ),
        versementsEnCours: comptes.reduce(
            (somme, c) => somme + c.versements_en_cours,
            0,
        ),
    };
}

/** « 1 versement à confirmer », « 2 versements à confirmer » ou « Aucun versement à confirmer ». */
export function libelleVersementsAConfirmer(nombre: number): string {
    if (nombre <= 0) {
        return 'Aucun versement à confirmer';
    }

    return `${nombre} ${nombre > 1 ? 'versements' : 'versement'} à confirmer`;
}
