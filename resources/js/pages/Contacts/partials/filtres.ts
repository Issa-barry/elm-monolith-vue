import type { OptionFiltre } from './types';

/**
 * Sous-catégories proposées par le filtre « Sous-catégorie » des listes Contacts : celles des
 * catégories (fonctions) cochées, ou toutes si aucune n'est cochée. Valeurs `categorie:code`.
 */
export function sousCategoriesDe(
    sousCategories: OptionFiltre[],
    categories: unknown,
): OptionFiltre[] {
    const choisies = Array.isArray(categories) ? categories.map(String) : [];
    if (choisies.length === 0) return sousCategories;

    return sousCategories.filter((o) =>
        choisies.includes(String(o.value).split(':')[0]),
    );
}
