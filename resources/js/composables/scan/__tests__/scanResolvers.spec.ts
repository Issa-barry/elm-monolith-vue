import { afterEach, describe, expect, it, vi } from 'vitest';
import { resolveInternalUrl, resolveQrText } from '../scanResolvers';

describe('resolveInternalUrl', () => {
    it("reconstruit une URL scannée du même site sur l'origine courante", () => {
        const url = resolveInternalUrl(
            `${window.location.origin}/proprietaires/01ABCDEF`,
        );
        expect(url).toBe(`${window.location.origin}/proprietaires/01ABCDEF`);
    });

    it("ne fabrique jamais de navigation vers l'hôte scanné — seul le chemin est repris, sur l'origine du navigateur", () => {
        const url = resolveInternalUrl('https://attaquant-externe.example/phishing');
        // Le host scanné ("attaquant-externe.example") est ignoré : seule l'origine
        // réellement affichée par le navigateur est utilisée, jamais une URL externe
        // arbitraire ouverte automatiquement.
        expect(url).toBe(`${window.location.origin}/phishing`);
        expect(url).not.toContain('attaquant-externe.example');
    });

    it("retourne null pour un texte qui n'est pas une URL", () => {
        expect(resolveInternalUrl('EAN1234567890')).toBeNull();
    });
});

describe('resolveQrText', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('résout une URL interne complète sans appel réseau (Cas 1)', async () => {
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);

        const result = await resolveQrText(
            `${window.location.origin}/proprietaires/01ABCDEF`,
        );

        expect(result).toEqual({
            status: 'resolved',
            url: `${window.location.origin}/proprietaires/01ABCDEF`,
        });
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('un texte sans URL/ULID/référence reconnue est "unrecognized" (aucun appel réseau)', async () => {
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);

        const result = await resolveQrText('texte quelconque non reconnu');

        expect(result).toEqual({ status: 'unrecognized' });
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('un ULID valide non résolu par le backend est "not_found"', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({ url: null }),
            }),
        );

        const result = await resolveQrText('01ARZ3NDEKTSV4RRFFQ69G5FAV');

        expect(result).toEqual({ status: 'not_found' });
    });
});
