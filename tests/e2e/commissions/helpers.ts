import { expect, type Page } from '@playwright/test';
import { selectOptionFromCombobox } from '../helpers';

/**
 * Helpers dédiés au moteur de commissions V2 (Vente), sur l'organisation
 * "Eau La Maman V2 Demo" (cf. ../helpers.ts::loginAsElmV2Demo,
 * database/seeders/Organizations/ElmV2Demo/*).
 *
 * Écrits contre le VRAI markup actuel de resources/js/pages/settings/CommissionRegles/Index.vue
 * (dialog unique avec checkbox par bénéficiaire + section Exceptions par type de véhicule) — pas
 * contre l'ancien pattern "une cellule cliquable par bénéficiaire" utilisé jusqu'ici par
 * tests/e2e/commission-v2-full-chain.spec.ts, qui ne correspond plus à l'écran actuel (cf. audit
 * de ce chantier : l'écran a été refondu, #cr-montant et les dialogs par bénéficiaire n'existent
 * plus).
 */

export const CIBLE_LABEL = {
    proprietaire: 'Propriétaire',
    livreur: 'Livreur',
    site: 'Site',
    consultant: 'Consultant',
} as const;

export type CibleCode = keyof typeof CIBLE_LABEL;

/** `null`/`undefined` = décoché (aucune commission pour cette cible). `0` est une valeur valide distincte. */
export type MontantsParCible = Partial<Record<CibleCode, number | null>>;

export interface ExceptionVehicule {
    typeVehiculeLabel: string;
    montants: MontantsParCible;
}

export interface ConfigurerBaremeOptions {
    categorieNom: string;
    montants: MontantsParCible;
    /** Requis si `montants.consultant` est un nombre. */
    consultantLabel?: string;
    exceptions?: ExceptionVehicule[];
}

/**
 * Fragment de regex matchant un montant tel que rendu par `Intl.NumberFormat('fr-FR')`
 * (espace fine insécable U+202F comme séparateur de milliers).
 */
export function montantPattern(amount: number): string {
    const formatted = new Intl.NumberFormat('fr-FR').format(amount);
    return formatted
        .split('')
        .map((ch) => (/\s/.test(ch) ? '\\s' : ch))
        .join('');
}

async function setBeneficiaire(
    page: Page,
    code: CibleCode,
    valeur: number | null | undefined,
): Promise<void> {
    const label = CIBLE_LABEL[code];
    const checkbox = page.getByRole('checkbox', { name: label, exact: true });
    await expect(checkbox).toBeVisible({ timeout: 10_000 });
    const dejaCoche = await checkbox.isChecked();

    if (valeur === null || valeur === undefined) {
        if (dejaCoche) await checkbox.click();
        return;
    }

    if (!dejaCoche) await checkbox.click();

    const montantInput = page.locator(
        `[aria-label="Montant général — ${label}"]`,
    );
    await expect(montantInput).toBeVisible({ timeout: 5_000 });
    await montantInput.fill(String(valeur));
}

/**
 * Ouvre le dialog de configuration pour `categorieNom` — "Ajouter une catégorie" si elle
 * n'a encore aucune ligne, "Modifier" si une ligne existe déjà (réutilisation entre scénarios
 * d'un même fichier, cf. commission-montants-zero.spec.ts qui reconfigure la même catégorie
 * plusieurs fois de suite).
 */
async function ouvrirDialogCategorie(
    page: Page,
    categorieNom: string,
): Promise<void> {
    await page.goto('/settings/commissions');
    await expect(
        page.getByRole('heading', { name: /^commissions$/i }),
    ).toBeVisible({ timeout: 15_000 });

    const row = page
        .locator('[data-testid^="commission-row-"]', {
            hasText: categorieNom,
        })
        .first();

    if (await row.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await row.getByRole('button', { name: /modifier/i }).click();
        return;
    }

    await page.getByTestId('commission-add-row').click();
    const categorieSelect = page.locator('[aria-label="Catégorie"]');
    await expect(categorieSelect).toBeVisible({ timeout: 10_000 });
    await selectOptionFromCombobox(page, categorieSelect, categorieNom);
}

/**
 * Configure (ou reconfigure) le barème d'une catégorie : bénéficiaires cochés/décochés,
 * montants (0 inclus), consultant désigné si nécessaire, exceptions par type de véhicule.
 * Va jusqu'à l'enregistrement serveur (confirmation + toast de succès).
 */
export async function configurerBareme(
    page: Page,
    opts: ConfigurerBaremeOptions,
): Promise<void> {
    await ouvrirDialogCategorie(page, opts.categorieNom);

    for (const code of Object.keys(CIBLE_LABEL) as CibleCode[]) {
        if (!(code in opts.montants)) continue;
        await setBeneficiaire(page, code, opts.montants[code]);
    }

    if (typeof opts.montants.consultant === 'number') {
        if (!opts.consultantLabel) {
            throw new Error(
                'configurerBareme: consultantLabel requis quand montants.consultant est défini.',
            );
        }
        const consultantSelect = page.locator(
            '[aria-label="Consultant bénéficiaire"]',
        );
        if (await consultantSelect.isVisible({ timeout: 3_000 }).catch(() => false)) {
            await selectOptionFromCombobox(
                page,
                consultantSelect,
                opts.consultantLabel,
            );
        }
    }

    for (const exception of opts.exceptions ?? []) {
        const vehiculeCheckbox = page.getByRole('checkbox', {
            name: exception.typeVehiculeLabel,
            exact: true,
        });
        await expect(vehiculeCheckbox).toBeVisible({ timeout: 5_000 });
        if (!(await vehiculeCheckbox.isChecked())) {
            await vehiculeCheckbox.click();
        }

        for (const code of Object.keys(CIBLE_LABEL) as CibleCode[]) {
            const montant = exception.montants[code];
            if (typeof montant !== 'number') continue;
            const label = CIBLE_LABEL[code];
            const input = page.locator(
                `[aria-label="Montant exceptionnel pour ${exception.typeVehiculeLabel} — ${label}"]`,
            );
            await expect(input).toBeVisible({ timeout: 5_000 });
            await input.fill(String(montant));
        }
    }

    await page.getByTestId('commission-dialog-save').click();
    await expect(page.getByTestId('commission-dialog-save')).toBeHidden({
        timeout: 10_000,
    });

    await page.getByTestId('commission-save').click();
    await page.getByTestId('commission-confirm-save').click();
    await expect(
        page.getByText(/commissions enregistrées/i),
    ).toBeVisible({ timeout: 15_000 });
}

/** Paramètres → Ventes : bascule le déclencheur de commission de vente. */
export async function configurerDeclencheurVente(
    page: Page,
    mode: 'chargement_valide' | 'facture_encaissee',
): Promise<void> {
    const label =
        mode === 'chargement_valide'
            ? 'À la validation du chargement'
            : "À l'encaissement de la facture";

    await page.goto('/settings/ventes');
    await expect(
        page.getByRole('heading', { name: /parametrage ventes/i }),
    ).toBeVisible({ timeout: 15_000 });

    const radio = page.getByRole('radio', { name: label });
    await expect(radio).toBeVisible({ timeout: 10_000 });
    if (await radio.isChecked()) {
        // Déjà dans l'état voulu : le bouton "Enregistrer" reste disabled
        // (form.isDirty === false), pas d'action serveur nécessaire.
        return;
    }
    await radio.click();

    const enregistrerBtn = page.getByRole('button', { name: /^enregistrer$/i });
    await expect(enregistrerBtn).toBeEnabled({ timeout: 5_000 });
    await enregistrerBtn.click();
    await expect(
        page.getByText(/enregistré|mis à jour|succès/i).first(),
    ).toBeVisible({ timeout: 15_000 });
}

export interface DiagnosticPart {
    id: string;
    beneficiaire_type: string;
    beneficiaire_id: string;
    montant_brut: number;
    montant_net: number;
    statut: string;
}

export interface DiagnosticLigne {
    id: string;
    variante_id: string;
    categorie_id_snapshot: string | null;
    quantite: number;
    montant_ligne: number;
}

export interface DiagnosticEnveloppe {
    id: string;
    cible_type: string;
    cible_id: string;
    montant_total: number;
    statut: string;
    earned_at: string | null;
    lignes_count: number;
    lignes: DiagnosticLigne[];
    parts: DiagnosticPart[];
}

export interface DiagnosticResponse {
    commande: {
        id: string;
        statut: string;
        commission_eligible_snapshot: boolean;
    };
    facture: {
        id: string;
        statut: string;
        encaissements: Array<{ id: string; montant: number }>;
    } | null;
    generation_attempts: Array<{
        id: string;
        statut: string;
        motif_erreur: string | null;
        created_at: string | null;
    }>;
    enveloppes: DiagnosticEnveloppe[];
    enveloppes_count: number;
    parts_count: number;
}

/**
 * Lit l'état réel commission_enveloppes/lignes/parts pour une commande, via l'endpoint
 * e2e-only (cf. app/Http/Controllers/Testing/CommissionE2eDiagnosticController.php,
 * jamais routé hors APP_ENV=e2e). Utilise le contexte de requête de la page (mêmes
 * cookies de session que le navigateur), pas de client HTTP séparé.
 */
export async function lireDiagnosticCommission(
    page: Page,
    commandeId: string,
): Promise<DiagnosticResponse> {
    const response = await page.request.get(
        `/e2e/diagnostics/commandes-vente/${commandeId}/commissions`,
    );
    if (!response.ok()) {
        throw new Error(
            `lireDiagnosticCommission: ${response.status()} ${await response.text()}`,
        );
    }
    return (await response.json()) as DiagnosticResponse;
}

/**
 * Supprime un encaissement via le VRAI endpoint applicatif (DELETE /encaissements/{id},
 * EncaissementVenteController::destroy()) — pas de bouton UI pour cette action aujourd'hui
 * (aucune trace dans Ventes/Show.vue au-delà de l'historique en lecture seule), donc un
 * appel HTTP direct authentifié par la session du navigateur est la façon la plus proche
 * du "vrai parcours" disponible, sans jamais appeler un service PHP directement. CSRF géré
 * comme le ferait Inertia/axios : lecture du cookie XSRF-TOKEN, envoyé en X-XSRF-TOKEN.
 */
export async function supprimerEncaissement(
    page: Page,
    encaissementId: string,
): Promise<void> {
    const cookies = await page.context().cookies();
    const xsrfCookie = cookies.find((c) => c.name === 'XSRF-TOKEN');
    if (!xsrfCookie) {
        throw new Error('supprimerEncaissement: cookie XSRF-TOKEN introuvable — session non authentifiée ?');
    }

    const response = await page.request.delete(`/encaissements/${encaissementId}`, {
        headers: {
            'X-XSRF-TOKEN': decodeURIComponent(xsrfCookie.value),
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (![200, 204, 302].includes(response.status())) {
        throw new Error(
            `supprimerEncaissement: statut HTTP inattendu ${response.status()} — ${await response.text()}`,
        );
    }
}

/** Extrait l'ULID de commande depuis l'URL courante (/backoffice/ventes/{id}). */
export function commandeIdFromUrl(url: string): string {
    const match = url.match(/\/ventes\/([a-z0-9]+)(?:[/?#]|$)/i);
    if (!match) {
        throw new Error(`commandeIdFromUrl: URL inattendue "${url}"`);
    }
    return match[1];
}
