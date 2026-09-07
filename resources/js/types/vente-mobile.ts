/** Informations de consultation déjà autorisées et partagées par la liste des ventes. */
export interface VenteMobile {
    id: string;
    reference: string;
    statut: string;
    statut_label: string;
    processus_label: string;
    created_at: string;
    vehicule_nom: string | null;
    vehicule_immatriculation: string | null;
    vehicule_photo_url: string | null;
    chauffeur_nom: string | null;
    client_nom: string | null;
    client_telephone: string | null;
    site_nom: string | null;
    total_commande: number;
    facture_statut: string | null;
    facture_statut_label: string | null;
    facture_montant_encaisse: number | null;
    facture_montant_restant: number | null;
}
