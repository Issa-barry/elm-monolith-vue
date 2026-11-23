import { dureeEnJours, libelleDuree } from '@/types/absence';
import { describe, expect, it } from 'vitest';

describe('durée d’une absence (aperçu du formulaire)', () => {
    it('compte les jours calendaires, bornes incluses', () => {
        expect(dureeEnJours('2026-11-10', '2026-11-12')).toBe(3);
        expect(dureeEnJours('2026-11-10', '2026-11-10')).toBe(1);
        // Week-end et changement de mois compris, sans décalage d'heure d'été.
        expect(dureeEnJours('2026-11-27', '2026-12-05')).toBe(9);
        expect(dureeEnJours('2026-03-28', '2026-03-30')).toBe(3);
    });

    it('ne propose rien tant que les dates sont incomplètes ou inversées', () => {
        expect(dureeEnJours('', '2026-11-12')).toBeNull();
        expect(dureeEnJours('2026-11-12', '')).toBeNull();
        expect(dureeEnJours('2026-11-12', '2026-11-10')).toBeNull();
    });

    it('accorde « jour » au singulier et au pluriel', () => {
        expect(libelleDuree(1)).toBe('1 jour');
        expect(libelleDuree(3)).toBe('3 jours');
    });
});
