import {
    evolutionEnPourcentage,
    formatPourcentage,
    partEnPourcentage,
    totalParts,
} from '@/lib/pourcentages';
import { describe, expect, it } from 'vitest';

describe('pourcentages des tableaux de bord', () => {
    it('affiche toujours une décimale avec la virgule française', () => {
        expect(formatPourcentage(56.74)).toBe('56,7 %');
        expect(formatPourcentage(42)).toBe('42,0 %');
        expect(formatPourcentage(100)).toBe('100,0 %');
        expect(formatPourcentage(0)).toBe('0,0 %');
        expect(formatPourcentage(14.25)).toBe('14,3 %');
    });

    it('calcule la part à partir des valeurs réelles, sans forcer le total à 100 %', () => {
        // Exemple de la comparaison des agences : 2 M, 1,5 M et 25 k sur 3,525 M.
        const parts = [2_000_000, 1_500_000, 25_000];
        const total = totalParts(parts.map((montant) => ({ montant })));

        expect(total).toBe(3_525_000);
        expect(parts.map((p) => partEnPourcentage(p, total))).toEqual([
            '56,7 %',
            '42,6 %',
            '0,7 %',
        ]);
        expect(partEnPourcentage(total, total)).toBe('100,0 %');
    });

    it('distingue une part infime d’une part nulle et gère l’absence de total', () => {
        expect(partEnPourcentage(1, 10_000)).toBe('< 0,1 %');
        expect(partEnPourcentage(0, 10_000)).toBe('0,0 %');
        expect(partEnPourcentage(5, 0)).toBe('0,0 %');
    });

    it('signe les évolutions avec une décimale', () => {
        expect(evolutionEnPourcentage(12.5)).toBe('+12,5 %');
        expect(evolutionEnPourcentage(-8)).toBe('-8,0 %');
        expect(evolutionEnPourcentage(0)).toBe('0,0 %');
        expect(evolutionEnPourcentage(2433.7)).toBe('+2 433,7 %');
    });
});
