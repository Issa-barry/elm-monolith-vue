import type { SituationVentesData } from '@/types/situation';

/** Fiche agent (Users/Show) — cf. docs/fiche-agent.md. */
export interface AgentFiche {
    id: string;
    prenom: string | null;
    nom: string | null;
    nom_complet: string;
    matricule: string | null;
    email: string | null;
    telephone: string | null;
    code_phone_pays: string | null;
    code_pays: string | null;
    pays: string | null;
    ville: string | null;
    adresse: string | null;
    role: string | null;
    role_label: string | null;
    is_active: boolean;
    is_pending_validation: boolean;
    sites: { id: string; nom: string; is_default: boolean }[];
}

export interface AgentEncaissementMoyen {
    cle: string;
    libelle: string;
    nombre: number;
    montant: number;
    pourcentage_montant: number;
}

/** Encaissements SAISIS par l'agent (axe trésorerie), distincts de l'« Encaissé » de ses ventes. */
export interface AgentEncaissementsData {
    montant: number;
    nombre: number;
    par_moyen: AgentEncaissementMoyen[];
}

export interface AgentSituationData {
    ventes: SituationVentesData;
    encaissements: AgentEncaissementsData;
}

export interface AgentDepenseLigne {
    id: string;
    date_depense: string | null;
    type: string | null;
    categorie: string | null;
    montant: number;
    statut: string;
    statut_label: string;
}

export interface AgentDepensesData {
    resume: {
        validees: { montant: number; nombre: number };
        en_attente: { montant: number; nombre: number };
        nombre: number;
    };
    lignes: AgentDepenseLigne[];
    total_lignes: number;
}
