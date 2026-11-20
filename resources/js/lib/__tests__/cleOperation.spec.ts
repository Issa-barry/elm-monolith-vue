import { nouvelleCleOperation, useCleOperation } from '@/lib/cleOperation';
import { describe, expect, it } from 'vitest';

describe('cleOperation', () => {
    it('génère un UUID v4 accepté par le serveur', () => {
        const cle = nouvelleCleOperation();

        expect(cle).toMatch(
            /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
        );
        expect(nouvelleCleOperation()).not.toBe(cle);
    });

    it('garde la même clé tant que les paramètres ne changent pas', () => {
        const operation = useCleOperation();
        const premiere = operation.pour({ montant: 50_000, mode: 'especes' });

        // Double clic, nouvelle tentative après une coupure : même opération.
        expect(operation.pour({ montant: 50_000, mode: 'especes' })).toBe(
            premiere,
        );
        // Paramètres corrigés : nouvelle opération, jamais la clé précédente.
        const corrigee = operation.pour({ montant: 60_000, mode: 'especes' });
        expect(corrigee).not.toBe(premiere);
        expect(operation.pour({ montant: 50_000, mode: 'especes' })).not.toBe(
            premiere,
        );
    });

    it('repart d’une nouvelle clé à chaque ouverture de la fenêtre', () => {
        const operation = useCleOperation();
        const premiere = operation.pour({ montant: 50_000 });

        operation.reinitialiser();

        expect(operation.pour({ montant: 50_000 })).not.toBe(premiere);
    });
});
