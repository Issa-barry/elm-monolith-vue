import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    LIVRAISON_REF_RE,
    resolveInternalUrl,
    resolveQrText,
} from '../scanResolvers';

describe('resolveInternalUrl', () => {
    it("reconstruit une URL scannée du même site sur l'origine courante", () => {
        const url = resolveInternalUrl(
            `${window.location.origin}/proprietaires/01ABCDEF`,
        );
        expect(url).toBe(`${window.location.origin}/proprietaires/01ABCDEF`);
    });

    it("ne fabrique jamais de navigation vers l'hôte scanné — seul le chemin est repris, sur l'origine du navigateur", () => {
        const url = resolveInternalUrl(
            'https://attaquant-externe.example/phishing',
        );
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

    // Régression : avant correctif, seul TR- était reconnu (voir
    // docs/scanner-dashboard-mobile.md) — VTE-/DST-/CMD-/TRF- (préfixes
    // actuels, cf. NatureOperation::prefixeReference() et
    // ScanCommandeController::COMMANDE_PREFIXES/TRANSFERT_PREFIXES côté API)
    // tombaient tous en "unrecognized" sans même appeler le backend.
    it.each([
        'VTE-060926-002',
        'DST-060926-001',
        'CMD-120825-045',
        'TRF-060926-007',
        'TR-12345-001',
    ])(
        'référence de livraison courante ou legacy "%s" déclenche la résolution backend',
        async (reference) => {
            const fetchSpy = vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({
                    url: `${window.location.origin}/backoffice/ventes/1`,
                }),
            });
            vi.stubGlobal('fetch', fetchSpy);

            const result = await resolveQrText(reference);

            expect(result).toEqual({
                status: 'resolved',
                url: `${window.location.origin}/backoffice/ventes/1`,
            });
            expect(fetchSpy).toHaveBeenCalledWith(
                `/scan/livraison/${encodeURIComponent(reference)}`,
                expect.anything(),
            );
        },
    );
});

describe('LIVRAISON_REF_RE', () => {
    it.each([
        'VTE-060926-002',
        'DST-060926-001',
        'CMD-120825-045',
        'TRF-060926-007',
        'TR-12345-001',
    ])('reconnaît le préfixe de "%s"', (reference) => {
        expect(LIVRAISON_REF_RE.test(reference)).toBe(true);
    });

    it('ne reconnaît pas une référence sans préfixe de livraison connu', () => {
        expect(LIVRAISON_REF_RE.test('EAN1234567890')).toBe(false);
    });
});
