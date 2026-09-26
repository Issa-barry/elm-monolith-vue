export interface PartageCommissionDetails {
    vehicule_nom: string;
    processus_libelle: string;
    processus_code: string;
    categories: {
        categorie_id: string;
        categorie_nom: string;
        bareme: number;
        total_configure: number | null;
        ecart: number;
        membres_manquants: string[];
        motif: string;
    }[];
}

export function estErreurPartage(message?: string | null): boolean {
    return (
        message?.startsWith(
            'Impossible de créer ou modifier cette commande : le partage de commission',
        ) ?? false
    );
}
