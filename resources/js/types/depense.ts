export interface DepenseRow {
    id: string;
    montant: number;
    date_depense: string;
    statut: string;
    statut_label: string;
    commentaire: string | null;
    type: {
        id: string;
        libelle: string;
        categorie: string;
        categorie_label: string;
    } | null;
    beneficiaire_type: string | null;
    beneficiaire_id: string | null;
    beneficiaire_label: string | null;
    beneficiaire_telephone: string | null;
    vehicule_id: string | null;
    vehicule_nom: string | null;
    vehicule_immatriculation: string | null;
    site: { id: string; nom: string } | null;
    user: { id: string; name: string };
    validateur: { id: string; name: string } | null;
    can_valider: boolean;
}
