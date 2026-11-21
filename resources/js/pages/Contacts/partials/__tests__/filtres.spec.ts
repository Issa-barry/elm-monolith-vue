import { sousCategoriesDe } from '@/pages/Contacts/partials/filtres';
import { describe, expect, it } from 'vitest';

const sousCategories = [
    { value: 'client:grossiste', label: 'Client — Grossiste' },
    { value: 'fournisseur:emballage', label: 'Fournisseur — Emballage' },
    { value: 'prestataire:consultant', label: 'Prestataire — Consultant' },
];

describe('sousCategoriesDe', () => {
    it('propose toutes les sous-catégories sans catégorie cochée', () => {
        expect(sousCategoriesDe(sousCategories, undefined)).toEqual(
            sousCategories,
        );
        expect(sousCategoriesDe(sousCategories, [])).toEqual(sousCategories);
    });

    it('ne garde que les sous-catégories des catégories cochées', () => {
        expect(
            sousCategoriesDe(sousCategories, ['client', 'prestataire']).map(
                (o) => o.value,
            ),
        ).toEqual(['client:grossiste', 'prestataire:consultant']);
    });

    it('ne propose rien pour une catégorie sans sous-catégorie', () => {
        expect(sousCategoriesDe(sousCategories, ['livreur'])).toEqual([]);
    });
});
