export interface ActorPayload {
    organization_name: string | null;
    profiles: string[];
    is_partner: boolean;
    client_id: number | null;
    proprietaire_id: number | null;
    livreur_id: number | null;
}

export interface VehiculeOption {
    id: number;
    nom_vehicule: string;
    immatriculation: string | null;
    type_label: string;
    capacite_packs: number | null;
    photo_url: string | null;
}

export interface TypeVehiculeOption {
    value: string;
    label: string;
    capacite_defaut: number;
}

export interface EarningsPayload {
    total_earned: number;
    total_paid: number;
    frais_depenses_total: number;
    balance: number;
    operations_count: number;
}

export interface EarningsVehiculePayload {
    vehicule_id: number;
    nom_vehicule: string;
    immatriculation: string | null;
    frais_depenses: number;
    total_earned: number;
    total_paid: number;
    balance: number;
}

export interface StatementLine {
    id: string;
    reference: string;
    vehicule_id: number | null;
    vehicule_nom: string;
    immatriculation: string | null;
    date_label: string | null;
    frais: number;
    montant_net: number;
    montant_verse: number;
    montant_restant: number;
    statut: string;
    statut_label: string;
}

export interface VehicleProposal {
    id: number;
    nom_vehicule: string;
    marque: string | null;
    modele: string | null;
    immatriculation: string;
    type_vehicule: string | null;
    capacite_packs: number | null;
    commentaire: string | null;
    statut: string;
    statut_label: string;
    decision_note: string | null;
    created_at_label: string | null;
}
