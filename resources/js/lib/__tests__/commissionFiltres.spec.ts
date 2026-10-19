import { describe, expect, it } from 'vitest';
import {
    ajouterStatutsEtPeriodes,
    libellePeriodes,
} from '../commissionFiltres';

const periodes = [
    { code: '2026-09-P1', label: 'P1 du 01 au 15 Septembre 2026' },
    { code: '2026-09-P2', label: 'P2 du 16 au 30 Septembre 2026' },
    { code: '2026-10-P1', label: 'P1 du 01 au 15 Octobre 2026' },
];

describe('libellePeriodes', () => {
    it('affiche « Toutes les périodes » sans sélection', () => {
        expect(libellePeriodes([], periodes)).toBe('Toutes les périodes');
    });

    it('affiche le libellé de la période unique', () => {
        expect(libellePeriodes(['2026-09-P1'], periodes)).toBe(
            'P1 du 01 au 15 Septembre 2026',
        );
    });

    it('joint deux périodes et compte au-delà', () => {
        expect(libellePeriodes(['2026-09-P1', '2026-09-P2'], periodes)).toBe(
            'P1 du 01 au 15 Septembre 2026 + P2 du 16 au 30 Septembre 2026',
        );
        expect(
            libellePeriodes(
                ['2026-09-P1', '2026-09-P2', '2026-10-P1'],
                periodes,
            ),
        ).toBe('3 périodes sélectionnées');
    });
});

describe('ajouterStatutsEtPeriodes', () => {
    it('envoie chaque statut et chaque période en tableau', () => {
        const params = ajouterStatutsEtPeriodes(
            new URLSearchParams(),
            ['impaye', 'partiel'],
            ['2026-09-P1', '2026-09-P2'],
        );

        expect(params.getAll('statut[]')).toEqual(['impaye', 'partiel']);
        expect(params.getAll('periode[]')).toEqual([
            '2026-09-P1',
            '2026-09-P2',
        ]);
    });
});
