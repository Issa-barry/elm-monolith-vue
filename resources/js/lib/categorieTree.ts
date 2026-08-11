export interface CategorieNode {
    id: string;
    nom: string;
    parent_id: string | null;
}

export interface FlatCategorieOption {
    id: string;
    /** Chemin complet ("Vêtements > Homme > T-shirts") — utile pour l'affichage replié. */
    label: string;
    /** Nom seul — pour un affichage indenté dans une liste ouverte / un arbre. */
    displayLabel: string;
    depth: number;
}

/**
 * Aplatit un arbre de catégories en respectant l'ordre parent → enfants (pas un tri
 * alphabétique global), avec le chemin complet et la profondeur de chaque nœud.
 * Utilisé à la fois par CategorieSelect.vue (formulaire Produit) et par la page
 * d'administration Produits/Categories/Index.vue — un seul point de vérité pour éviter
 * que les deux dérivent (ex: ordre différent, chemin construit différemment).
 */
export function flattenCategorieTree<T extends CategorieNode>(
    categories: T[],
): FlatCategorieOption[] {
    const rootKey = (id: string | null) => id ?? '__root__';
    const parEnfants = new Map<string, T[]>();
    for (const c of categories) {
        const key = rootKey(c.parent_id);
        if (!parEnfants.has(key)) parEnfants.set(key, []);
        parEnfants.get(key)!.push(c);
    }
    for (const liste of parEnfants.values()) {
        liste.sort((a, b) => a.nom.localeCompare(b.nom));
    }

    const cheminDe = (categorie: T): string => {
        const parent = categories.find((c) => c.id === categorie.parent_id);

        return parent
            ? `${cheminDe(parent)} > ${categorie.nom}`
            : categorie.nom;
    };

    const resultat: FlatCategorieOption[] = [];
    const parcourir = (parentId: string | null, depth: number) => {
        for (const c of parEnfants.get(rootKey(parentId)) ?? []) {
            resultat.push({
                id: c.id,
                label: cheminDe(c),
                displayLabel: c.nom,
                depth,
            });
            parcourir(c.id, depth + 1);
        }
    };
    parcourir(null, 0);

    return resultat;
}
