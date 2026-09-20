import { Landmark, Smartphone, UserRound, Wallet } from 'lucide-vue-next';
import { describe, expect, it } from 'vitest';
import {
    actionsMenu,
    alerteSoldeOuverture,
    libelleVersementsAConfirmer,
    natureAffichee,
    resumeSupports,
    type CompteTresorerie,
} from '../presentation';

const soldeOuverture = (statut: string) => ({
    id: 's1',
    montant: 1000,
    statut,
});

describe('natureAffichee', () => {
    it("distingue la caisse dédiée à un agent des supports de l'agence", () => {
        const nature = natureAffichee({
            nature: 'dediee',
            type: 'caisse',
            type_label: 'Caisse',
        });

        expect(nature.label).toBe('Caisse dédiée');
        expect(nature.titre).toBe('Caisse dédiée à un agent');
        expect(nature.icone).toBe(UserRound);
        expect(nature.variante).toBe('secondary');
    });

    it.each([
        ['caisse', 'Caisse agence', Wallet],
        ['banque', 'Banque', Landmark],
        ['mobile_money', 'Mobile Money', Smartphone],
    ])("affiche le type %s d'un support d'agence", (type, label, icone) => {
        const nature = natureAffichee({
            nature: 'agence',
            type,
            type_label: 'peu importe',
        });

        expect(nature.label).toBe(label);
        expect(nature.icone).toBe(icone);
        expect(nature.variante).toBe('outline');
    });

    it('retombe sur le libellé du type pour un type inconnu', () => {
        expect(
            natureAffichee({
                nature: 'agence',
                type: 'cheque_cadeau',
                type_label: 'Chèque cadeau',
            }).label,
        ).toBe('Chèque cadeau');
    });
});

describe('actionsMenu', () => {
    const agence = (
        surcharge: Partial<
            Pick<CompteTresorerie, 'statut' | 'solde_ouverture'>
        > = {},
    ) => ({
        nature: 'agence' as const,
        statut: 'actif' as const,
        solde_ouverture: null,
        ...surcharge,
    });

    it('ne propose aucune action sans la permission de gérer les supports', () => {
        expect(actionsMenu(agence(), false)).toEqual([]);
        expect(
            actionsMenu(
                { nature: 'dediee', statut: 'actif', solde_ouverture: null },
                false,
            ),
        ).toEqual([]);
    });

    it("ne propose que « Modifier » pour un brouillon : ni solde d'ouverture, ni activation par « Réactiver »", () => {
        expect(actionsMenu(agence({ statut: 'brouillon' }), true)).toEqual([
            'modifier',
        ]);
        expect(
            actionsMenu(
                {
                    nature: 'dediee',
                    statut: 'brouillon',
                    solde_ouverture: null,
                },
                true,
            ),
        ).toEqual(['modifier']);
    });

    it("propose de saisir le solde d'ouverture tant qu'il n'existe pas", () => {
        expect(actionsMenu(agence(), true)).toEqual([
            'modifier',
            'saisir_solde_ouverture',
            'desactiver',
        ]);
    });

    it("propose de valider un solde d'ouverture en brouillon", () => {
        expect(
            actionsMenu(
                agence({ solde_ouverture: soldeOuverture('brouillon') }),
                true,
            ),
        ).toEqual(['modifier', 'valider_solde_ouverture', 'desactiver']);
    });

    it("ne propose plus rien sur le solde d'ouverture une fois validé", () => {
        expect(
            actionsMenu(
                agence({ solde_ouverture: soldeOuverture('valide') }),
                true,
            ),
        ).toEqual(['modifier', 'desactiver']);
    });

    it("n'a jamais de solde d'ouverture pour une caisse dédiée", () => {
        expect(
            actionsMenu(
                { nature: 'dediee', statut: 'actif', solde_ouverture: null },
                true,
            ),
        ).toEqual(['modifier', 'desactiver']);
    });

    it('propose de réactiver un support inactif', () => {
        expect(
            actionsMenu(
                {
                    nature: 'dediee',
                    statut: 'inactif',
                    solde_ouverture: null,
                },
                true,
            ),
        ).toEqual(['modifier', 'reactiver']);
    });
});

describe('alerteSoldeOuverture', () => {
    const support = (
        surcharge: Partial<
            Pick<CompteTresorerie, 'nature' | 'statut' | 'solde_ouverture'>
        > = {},
    ) => ({
        nature: 'agence' as const,
        statut: 'actif' as const,
        solde_ouverture: null,
        ...surcharge,
    });

    it("signale un solde d'ouverture à saisir sur un support actif d'agence", () => {
        expect(alerteSoldeOuverture(support())).toBe(
            "Solde d'ouverture à saisir",
        );
    });

    it("signale un solde d'ouverture à valider, avec son montant et son absence du solde", () => {
        const alerte = alerteSoldeOuverture(
            support({ solde_ouverture: soldeOuverture('brouillon') }),
        );

        expect(alerte).toContain("Solde d'ouverture à valider");
        expect(alerte).toContain('1 000');
        expect(alerte).toContain('non compté dans le solde');
    });

    it("ne dit rien d'un solde d'ouverture validé", () => {
        expect(
            alerteSoldeOuverture(
                support({ solde_ouverture: soldeOuverture('valide') }),
            ),
        ).toBeNull();
    });

    it("ne dit rien d'une caisse dédiée, d'un brouillon ni d'un support désactivé", () => {
        expect(alerteSoldeOuverture(support({ nature: 'dediee' }))).toBeNull();
        expect(
            alerteSoldeOuverture(support({ statut: 'brouillon' })),
        ).toBeNull();
        expect(alerteSoldeOuverture(support({ statut: 'inactif' }))).toBeNull();
    });
});

describe('resumeSupports', () => {
    const ligne = (
        nature: 'agence' | 'dediee',
        solde: number,
        enCours = 0,
        versements = 0,
    ) => ({
        nature,
        solde,
        en_cours_versement: enCours,
        versements_en_cours: versements,
    });

    it('compte les supports, les caisses dédiées et additionne les soldes', () => {
        expect(
            resumeSupports([
                ligne('dediee', 1_000_000),
                ligne('agence', 266_824_000),
                ligne('agence', 0),
                ligne('agence', 3_361_000),
            ]),
        ).toEqual({
            total: 4,
            caissesAgents: 1,
            soldeTotal: 271_185_000,
            enCoursVersement: 0,
            versementsEnCours: 0,
        });
    });

    it('est vide sans support', () => {
        expect(resumeSupports([])).toEqual({
            total: 0,
            caissesAgents: 0,
            soldeTotal: 0,
            enCoursVersement: 0,
            versementsEnCours: 0,
        });
    });

    it('additionne les versements en cours sans jamais les mêler au solde total', () => {
        // Caisse de l'agent : 850 000 avant, 800 000 envoyés → 50 000 de solde + 800 000 en cours.
        const resume = resumeSupports([
            ligne('dediee', 50_000, 800_000, 1),
            ligne('agence', 0),
        ]);

        expect(resume.soldeTotal).toBe(50_000);
        expect(resume.enCoursVersement).toBe(800_000);
        expect(resume.versementsEnCours).toBe(1);
    });
});

describe('libelleVersementsAConfirmer', () => {
    it.each([
        [0, 'Aucun versement à confirmer'],
        [1, '1 versement à confirmer'],
        [3, '3 versements à confirmer'],
    ])('%i versement(s) → « %s »', (nombre, libelle) => {
        expect(libelleVersementsAConfirmer(nombre)).toBe(libelle);
    });
});
