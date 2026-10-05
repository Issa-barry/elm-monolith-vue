import { describe, expect, it } from 'vitest';
import { resumeActivite } from '../activiteVente';

const espaces = (texte: string) => texte.replace(/[  ]/g, ' ');

describe('resumeActivite', () => {
    it('affiche le montant d’un acompte ou d’un remboursement', () => {
        expect(espaces(resumeActivite({ montant: 2000000 }))).toBe(
            '2 000 000 GNF',
        );
    });

    it('additionne les quantités par ligne de la préparation et du retrait', () => {
        expect(espaces(resumeActivite({ quantites: { a: 500, b: 40 } }))).toBe(
            '540 unités',
        );
    });

    it('résume la création d’une précommande : acompte et date prévue', () => {
        expect(
            espaces(
                resumeActivite({
                    date_remise_prevue: '2026-10-06',
                    acompte: 2000000,
                }),
            ),
        ).toBe('Acompte 2 000 000 GNF · Remise prévue le 06/10/2026');
    });

    it('n’affiche rien sans détail utile', () => {
        expect(resumeActivite(null)).toBe('');
        expect(resumeActivite({ motif: 'Client absent', acompte: 0 })).toBe('');
    });
});
