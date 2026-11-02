import { describe, expect, it, vi } from 'vitest';
import { traiterParLots } from '../traiterParLots';

describe('traiterParLots', () => {
    it('découpe les éléments et signale la progression', async () => {
        const envoyer = vi.fn().mockResolvedValue(undefined);
        const progression: number[] = [];

        const resultat = await traiterParLots(
            [1, 2, 3, 4, 5],
            2,
            envoyer,
            (traites) => progression.push(traites),
        );

        expect(envoyer.mock.calls.map(([lot]) => lot)).toEqual([
            [1, 2],
            [3, 4],
            [5],
        ]);
        expect(progression).toEqual([2, 4, 5]);
        expect(resultat).toEqual({ traites: 5, erreur: null });
    });

    it("s'arrête au premier échec et garde ce qui est déjà passé", async () => {
        const envoyer = vi
            .fn()
            .mockResolvedValueOnce(undefined)
            .mockRejectedValueOnce(new Error('Délai dépassé'));

        const resultat = await traiterParLots([1, 2, 3, 4, 5], 2, envoyer);

        expect(envoyer).toHaveBeenCalledTimes(2);
        expect(resultat).toEqual({ traites: 2, erreur: 'Délai dépassé' });
    });

    it("n'envoie rien pour une liste vide", async () => {
        const envoyer = vi.fn();

        const resultat = await traiterParLots([], 2, envoyer);

        expect(envoyer).not.toHaveBeenCalled();
        expect(resultat).toEqual({ traites: 0, erreur: null });
    });
});
