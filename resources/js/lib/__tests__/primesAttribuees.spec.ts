import { describe, expect, it } from 'vitest';
import {
    dateEffetProposee,
    libellesPrimesActuelles,
    moisVersementPropose,
    periodeDuMois,
    primeDejaAttribuee,
    SANS_FIN,
    type PrimeOccupee,
} from '../primesAttribuees';

const prime = (surcharges: Partial<PrimeOccupee> = {}): PrimeOccupee => ({
    type_id: 'transport',
    libelle: 'Transport',
    date_debut: '2026-10-01',
    date_fin: null,
    a_valider: false,
    ...surcharges,
});

describe('primeDejaAttribuee', () => {
    it('une prime sans fin occupe toute période ultérieure', () => {
        expect(
            primeDejaAttribuee(
                [prime()],
                'transport',
                false,
                '2026-11-01',
                SANS_FIN,
            ),
        ).toBe(true);
    });

    it('une prime terminée laisse libre la période qui suit sa fin', () => {
        const terminee = prime({ date_fin: '2026-10-31' });

        expect(
            primeDejaAttribuee(
                [terminee],
                'transport',
                false,
                '2026-11-01',
                SANS_FIN,
            ),
        ).toBe(false);
        expect(
            primeDejaAttribuee(
                [terminee],
                'transport',
                false,
                '2026-10-31',
                SANS_FIN,
            ),
        ).toBe(true);
    });

    it('une prime ponctuelle ne bloque que son mois', () => {
        const ponctuelle = prime({
            date_debut: '2026-11-01',
            date_fin: '2026-11-30',
        });
        const [du, au] = periodeDuMois('2026-12');

        expect(
            primeDejaAttribuee([ponctuelle], 'transport', false, du, au),
        ).toBe(false);
    });

    it('une prime qui commence après la période visée ne la bloque pas', () => {
        expect(
            primeDejaAttribuee(
                [prime({ date_debut: '2027-01-01' })],
                'transport',
                false,
                '2026-11-01',
                '2026-12-31',
            ),
        ).toBe(false);
    });

    it('un autre type ou un type à cumul autorisé n’est jamais bloqué', () => {
        expect(
            primeDejaAttribuee(
                [prime()],
                'logement',
                false,
                '2026-11-01',
                SANS_FIN,
            ),
        ).toBe(false);
        expect(
            primeDejaAttribuee(
                [prime()],
                'transport',
                true,
                '2026-11-01',
                SANS_FIN,
            ),
        ).toBe(false);
    });
});

describe('periodeDuMois', () => {
    it('donne le premier et le dernier jour, années bissextiles comprises', () => {
        expect(periodeDuMois('2026-11')).toEqual(['2026-11-01', '2026-11-30']);
        expect(periodeDuMois('2028-02')).toEqual(['2028-02-01', '2028-02-29']);
    });
});

describe('libellesPrimesActuelles', () => {
    it('reprend les primes en cours ou à venir, une fois chacune', () => {
        expect(
            libellesPrimesActuelles(
                [
                    prime({
                        date_fin: '2026-10-31',
                        libelle: 'Ancienne',
                        type_id: 'ancienne',
                    }),
                    prime(),
                    prime({ date_debut: '2027-01-01' }),
                    prime({
                        type_id: 'logement',
                        libelle: 'Logement',
                        a_valider: true,
                    }),
                ],
                '2026-11-01',
            ),
        ).toEqual(['Transport', 'Logement (à valider)']);
    });
});

describe('date proposée pour une prime d’un nouveau contrat', () => {
    it('reprend le début du contrat', () => {
        expect(dateEffetProposee('2026-11-15', null)).toBe('2026-11-15');
        expect(dateEffetProposee('', null)).toBe('');
    });

    it('passe au premier jour modifiable si le mois du contrat est figé', () => {
        expect(dateEffetProposee('2023-01-01', '2026-11-01')).toBe(
            '2026-11-01',
        );
        expect(dateEffetProposee('2026-12-01', '2026-11-01')).toBe(
            '2026-12-01',
        );
    });

    it('propose le mois suivant pour une prime ponctuelle si le contrat commence en cours de mois', () => {
        expect(moisVersementPropose('2026-11-01', null)).toBe('2026-11');
        expect(moisVersementPropose('2026-11-15', null)).toBe('2026-12');
        expect(moisVersementPropose('2026-12-15', null)).toBe('2027-01');
        expect(moisVersementPropose('', null)).toBe('');
    });
});
