import { describe, expect, it } from 'vitest';
import {
    poolVehiculesPourClient,
    vehiculeEstDansPool,
} from '../useDistributionVehiculePool';

interface VehiculeStub {
    id: number;
    nom_vehicule: string;
}

const vehiculesVente: VehiculeStub[] = [
    { id: 1, nom_vehicule: 'Camion Vente A' },
    { id: 2, nom_vehicule: 'Camion Vente B' },
];

const vehiculesDistribution: VehiculeStub[] = [
    { id: 10, nom_vehicule: 'Camion Logistique A' },
    { id: 11, nom_vehicule: 'Camion Logistique B' },
];

describe('poolVehiculesPourClient', () => {
    it('retourne le pool distribution pour un client distributeur', () => {
        expect(
            poolVehiculesPourClient(
                'distributeur',
                vehiculesVente,
                vehiculesDistribution,
            ),
        ).toBe(vehiculesDistribution);
    });

    it('retourne le pool vente pour un client externe', () => {
        expect(
            poolVehiculesPourClient(
                'externe',
                vehiculesVente,
                vehiculesDistribution,
            ),
        ).toBe(vehiculesVente);
    });

    it('retourne le pool vente pour un client revendeur', () => {
        expect(
            poolVehiculesPourClient(
                'revendeur',
                vehiculesVente,
                vehiculesDistribution,
            ),
        ).toBe(vehiculesVente);
    });

    it("retourne le pool vente quand aucun client n'est sélectionné (undefined/null)", () => {
        expect(
            poolVehiculesPourClient(
                undefined,
                vehiculesVente,
                vehiculesDistribution,
            ),
        ).toBe(vehiculesVente);
        expect(
            poolVehiculesPourClient(
                null,
                vehiculesVente,
                vehiculesDistribution,
            ),
        ).toBe(vehiculesVente);
    });
});

describe('vehiculeEstDansPool', () => {
    it("retourne true quand aucun véhicule n'est sélectionné", () => {
        expect(vehiculeEstDansPool(null, vehiculesVente)).toBe(true);
    });

    it('retourne true quand le véhicule appartient au pool courant', () => {
        expect(vehiculeEstDansPool(1, vehiculesVente)).toBe(true);
        expect(vehiculeEstDansPool(10, vehiculesDistribution)).toBe(true);
    });

    it('retourne false quand le véhicule est incompatible avec le pool courant', () => {
        // Un véhicule distribution-only ne doit jamais rester valide dans le pool vente...
        expect(vehiculeEstDansPool(10, vehiculesVente)).toBe(false);
        // ...ni l'inverse.
        expect(vehiculeEstDansPool(1, vehiculesDistribution)).toBe(false);
    });
});
