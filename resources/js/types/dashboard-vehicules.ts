/**
 * Données de Stat-Véhicules V1 (TableauDeBordVehiculesService, docs/tableau-de-bord-vehicules.md).
 * Un bloc à `null` est celui d'une source que l'utilisateur ne peut pas consulter : il n'est ni
 * calculé ni envoyé par le serveur.
 */

/** Type d'opération d'une rotation, et usage du véhicule qui la permet. */
export type CodeOperation =
    | 'vente'
    | 'distribution'
    | 'grossiste'
    | 'transfert';

/** Sources lisibles par l'utilisateur (permission et module). */
export interface BlocsVehicules {
    ventes: boolean;
    transferts: boolean;
    depenses: boolean;
}

/** État du parc à ce jour (agence actuelle des véhicules). */
export interface ParcVehicules {
    total: number;
    actifs: number;
    inactifs: number;
    operationnels: number;
    a_configurer: number;
    /** null sans accès ni aux ventes ni aux transferts. */
    en_mission: {
        vehicules: number;
        ventes: number | null;
        transferts: number | null;
    } | null;
}

export interface OperationVehicules {
    code: CodeOperation;
    libelle: string;
    usage: string;
    /** Véhicules actifs du parc qui ont l'usage. */
    vehicules: number;
    /** Parmi eux, ceux qui peuvent réaliser l'opération aujourd'hui. */
    possibles: number;
    bloques: number;
    /** null : source (ventes ou transferts) non autorisée. */
    rotations: number | null;
    vehicules_utilises: number | null;
    jours_vehicule: number | null;
}

export interface ActiviteVehicules {
    rotations: number;
    vehicules_utilises: number;
    actifs: number;
    actifs_utilises: number;
    taux_utilisation: number | null;
    jours_vehicule: number;
    avec_retour: number;
    taux_retour: number | null;
    /** null sans accès aux transferts. */
    transferts_avec_ecart: number | null;
}

export interface EvolutionVehicules {
    granularite: 'jour' | 'mois';
    tranches: { cle: string; label: string }[];
    series: { code: CodeOperation; label: string; valeurs: number[] }[];
}

/** Une catégorie de produit : quantités jamais additionnées à une autre catégorie. */
export interface CategorieVehicules {
    categorie_id: string | null;
    categorie: string;
    capacite_parc: number;
    vehicules_plafonnes: number;
    charge: number | null;
    chargements: number | null;
    remplissage: number | null;
    retourne: number | null;
    taux_retour: number | null;
    ecarts_manquants: number | null;
    ecarts_surplus: number | null;
}

export interface DepensesVehicules {
    montant: number;
    nombre: number;
    vehicules: number;
    /** null sans accès à l'activité. */
    rotations: number | null;
    cout_par_rotation: number | null;
}

export interface VehiculeClasse {
    id: string;
    nom: string;
    immatriculation: string | null;
    site: string;
    type: string;
    usages: string;
    statut: 'operationnel' | 'a_configurer' | 'inactif';
    statut_label: string;
    supprime: boolean;
    rotations: number | null;
    jours: number | null;
    avec_retour: number | null;
    depenses: number | null;
}

export interface VehiculeAConfigurer {
    id: string;
    nom: string;
    immatriculation: string | null;
    site: string;
    usages: string;
    motifs: string[];
}
