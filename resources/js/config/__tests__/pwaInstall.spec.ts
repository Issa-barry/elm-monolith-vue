import { describe, expect, it } from 'vitest';
import {
    isIosDevice,
    isPhoneDevice,
    isStandaloneDisplay,
    resolvePwaInstallState,
} from '../pwaInstall';

describe('isPhoneDevice', () => {
    it('reconnaît un iPhone', () => {
        expect(
            isPhoneDevice(
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
                5,
                390,
            ),
        ).toBe(true);
    });

    it('exclut un iPad (UA explicite)', () => {
        expect(
            isPhoneDevice(
                'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X)',
                5,
                820,
            ),
        ).toBe(false);
    });

    it('exclut un iPad en déguisement UA "Macintosh" (iPadOS 13+)', () => {
        expect(
            isPhoneDevice(
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_6)',
                5,
                1024,
            ),
        ).toBe(false);
    });

    it('ne confond jamais un vrai Mac (souris, 0 point tactile) avec un téléphone', () => {
        expect(
            isPhoneDevice(
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_6)',
                0,
                1440,
            ),
        ).toBe(false);
    });

    it('reconnaît un téléphone Android via le token UA "Mobile"', () => {
        expect(
            isPhoneDevice(
                'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Mobile Safari/537.36',
                5,
                412,
            ),
        ).toBe(true);
    });

    it('exclut une tablette Android (UA sans token "Mobile")', () => {
        expect(
            isPhoneDevice(
                'Mozilla/5.0 (Linux; Android 14; SM-X200) AppleWebKit/537.36 Safari/537.36',
                5,
                800,
            ),
        ).toBe(false);
    });

    it('exclut un desktop classique (pas de tactile)', () => {
        expect(
            isPhoneDevice(
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                0,
                1920,
            ),
        ).toBe(false);
    });

    it("repli sur la largeur d'écran pour un UA inconnu tactile étroit", () => {
        expect(isPhoneDevice('Mozilla/5.0 (Unknown OS)', 5, 360)).toBe(true);
    });

    it("repli sur la largeur d'écran pour un UA inconnu tactile large (tablette)", () => {
        expect(isPhoneDevice('Mozilla/5.0 (Unknown OS)', 5, 900)).toBe(false);
    });
});

describe('isIosDevice', () => {
    it('reconnaît iPhone et iPad', () => {
        expect(isIosDevice('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)', 5)).toBe(
            true,
        );
        expect(isIosDevice('Mozilla/5.0 (iPad; CPU OS 17_0)', 5)).toBe(true);
    });

    it('exclut un vrai Mac (pas de tactile)', () => {
        expect(
            isIosDevice('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_6)', 0),
        ).toBe(false);
    });
});

describe('isStandaloneDisplay', () => {
    it('détecte le mode standalone via matchMedia (Android/desktop)', () => {
        expect(isStandaloneDisplay(true, undefined)).toBe(true);
    });

    it('détecte le mode standalone via navigator.standalone (iOS)', () => {
        expect(isStandaloneDisplay(false, true)).toBe(true);
    });

    it("n'est jamais standalone sans aucun des deux signaux", () => {
        expect(isStandaloneDisplay(false, false)).toBe(false);
        expect(isStandaloneDisplay(false, undefined)).toBe(false);
    });
});

describe('resolvePwaInstallState', () => {
    it('reste "hidden" une fois déjà installée, même avec une invite native disponible', () => {
        expect(
            resolvePwaInstallState({
                isStandalone: true,
                isIos: false,
                hasDeferredPrompt: true,
            }),
        ).toBe('hidden');
    });

    it("priorise l'invite native dès qu'elle est capturée", () => {
        expect(
            resolvePwaInstallState({
                isStandalone: false,
                isIos: false,
                hasDeferredPrompt: true,
            }),
        ).toBe('native_prompt');
    });

    it("retombe sur les instructions iOS en l'absence d'invite native", () => {
        expect(
            resolvePwaInstallState({
                isStandalone: false,
                isIos: true,
                hasDeferredPrompt: false,
            }),
        ).toBe('ios_instructions');
    });

    it('reste "hidden" sans aucun chemin détecté (navigateur non supporté)', () => {
        expect(
            resolvePwaInstallState({
                isStandalone: false,
                isIos: false,
                hasDeferredPrompt: false,
            }),
        ).toBe('hidden');
    });
});
