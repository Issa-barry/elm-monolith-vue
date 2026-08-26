import { expect, test, type Page } from '@playwright/test';
import {
    login,
    randomDigits,
    registerCleanup,
    selectOptionFromCombobox,
} from './helpers';

const PREFIX = 'e2eimpayes';

test.setTimeout(180_000);

registerCleanup('/backoffice/clients', PREFIX);
registerCleanup('/backoffice/vehicules', PREFIX);

// ── Paramètres > Ventes — contrôle des impayés ──────────────────────────────

async function getImpayesToggle(page: Page) {
    const section = page
        .locator('.overflow-hidden')
        .filter({ hasText: /controle des impayes/i })
        .first();
    await expect(section).toBeVisible({ timeout: 10_000 });
    return section.getByRole('switch').first();
}

/**
 * Configure explicitement le contrôle des impayés (actif + seuil) via l'écran Paramètres >
 * Ventes — jamais en supposant l'état par défaut, pour que ce test reste indépendant de
 * l'ordre d'exécution des autres specs E2E (même principe que setChargementCompletRequired
 * dans vente-parametrage-chargement.spec.ts).
 */
async function setImpayesControle(
    page: Page,
    actif: boolean,
    seuil: number,
): Promise<void> {
    await page.goto('/settings/ventes');
    await expect(page).toHaveURL(/\/settings\/ventes$/, { timeout: 20_000 });

    const toggle = await getImpayesToggle(page);
    const checked = (await toggle.getAttribute('aria-checked')) === 'true';
    const seuilInput = page.locator('#seuil-impayes-input');
    const currentSeuil = actif
        ? await seuilInput.inputValue().catch(() => '')
        : '';
    // formatSeuil() (Ventes.vue) affiche 0/null comme une chaîne VIDE, jamais "0" — sans le
    // `|| '0'`, comparer "" à String(0) échouait toujours, forçant une resoumission inutile
    // d'une valeur déjà à 0 : `form.seuil_impayes_max` ne changeant alors pas réellement (0 ->
    // 0), `form.isDirty` restait false, le bouton "Enregistrer" restait disabled et le .click()
    // ci-dessous bloquait jusqu'au timeout du test entier (cf. tests 92/110 de ce fichier).
    const currentSeuilDigits = currentSeuil.replace(/\D/g, '') || '0';
    const seuilDejaBon = !actif || currentSeuilDigits === String(seuil);

    // Rien à faire si l'état visé est déjà en place — comme setChargementCompletRequired
    // dans vente-parametrage-chargement.spec.ts, évite une soumission (et son aller-retour
    // réseau) inutile à chaque nettoyage post-test.
    if (checked === actif && seuilDejaBon) {
        return;
    }

    if (checked !== actif) {
        await toggle.click();
        await expect(toggle).toHaveAttribute(
            'aria-checked',
            actif ? 'true' : 'false',
            { timeout: 5_000 },
        );
    }

    if (actif) {
        await seuilInput.click();
        await seuilInput.fill(String(seuil));
        await seuilInput.blur();
    }

    await page
        .getByRole('button', { name: /enregistrer/i })
        .last()
        .click();
    await expect(page.locator('body')).toContainText(/mis a jour/i, {
        timeout: 10_000,
    });
}

test.describe('Paramètres > Ventes — défauts contrôle des impayés et commissions', () => {
    test.afterEach(async ({ page }) => {
        // Restaure les défauts (actif, seuil 0) après chaque test de ce describe pour ne
        // jamais laisser un seuil de test polluer le reste de la suite E2E. Réutilise la
        // page déjà authentifiée du test (storageState global, cf. playwright.config.ts) au
        // lieu d'un nouveau contexte + login à froid — plus simple et plus fiable qu'un
        // second cycle d'authentification complet juste pour un nettoyage.
        try {
            await setImpayesControle(page, true, 0);
        } catch {
            // ne pas faire échouer la suite si le cleanup plante
        }
    });

    test('affiche le contrôle des impayés actif et le seuil à 0 par défaut', async ({
        page,
    }) => {
        await login(page);
        await page.goto('/settings/ventes');
        await expect(page).toHaveURL(/\/settings\/ventes$/, {
            timeout: 20_000,
        });

        const toggle = await getImpayesToggle(page);
        await expect(toggle).toHaveAttribute('aria-checked', 'true', {
            timeout: 10_000,
        });

        const seuilInput = page.locator('#seuil-impayes-input');
        await expect(seuilInput).toHaveAttribute('placeholder', '0');
    });

    test('affiche "À l\'encaissement de la facture" comme commission de vente par défaut', async ({
        page,
    }) => {
        await login(page);
        await page.goto('/settings/ventes');
        await expect(page).toHaveURL(/\/settings\/ventes$/, {
            timeout: 20_000,
        });

        await expect(page.locator('body')).toContainText(
            /l'encaissement de la facture/i,
            { timeout: 10_000 },
        );
        await expect(page.locator('body')).toContainText(/à la réception/i, {
            timeout: 10_000,
        });
    });

    test('le seuil configuré est persisté après rechargement', async ({
        page,
    }) => {
        await login(page);
        await setImpayesControle(page, true, 1_500_000);

        await page.goto('/settings/ventes');
        await expect(page).toHaveURL(/\/settings\/ventes$/, {
            timeout: 20_000,
        });

        // Intl.NumberFormat('fr-FR') sépare les milliers par une espace fine insécable
        // (U+202F), pas une espace normale — un littéral "1 500 000" ne matcherait jamais.
        const seuilInput = page.locator('#seuil-impayes-input');
        await expect(seuilInput).toHaveValue(/^1\D500\D000$/, {
            timeout: 10_000,
        });
    });
});

// ── Véhicule — dérogation au seuil d'impayés (individuelle, portée par le véhicule) ──
// Note : contrairement à /backoffice/vehicules et /backoffice/clients, la suppression sur
// /backoffice/type-vehicules passe par un window.confirm() natif (pas le menu "..." +
// modale attendus par registerCleanup()/cleanupRowsByPrefix()) et reste bloquée tant qu'un
// véhicule y est rattaché — les types créés par ces tests ne sont donc volontairement pas
// nettoyés automatiquement (clutter de test à faible risque, jamais lu par une organisation
// réelle grâce au préfixe PREFIX).

async function createTypeVehiculeInApp(page: Page, nom: string): Promise<void> {
    await page.goto('/backoffice/type-vehicules/create');
    await expect(page).toHaveURL(/\/type-vehicules\/create$/, {
        timeout: 20_000,
    });

    await page.locator('#nom').fill(nom);

    await page.getByRole('button', { name: /créer/i }).click();
    await expect(page).toHaveURL(/\/type-vehicules$/, { timeout: 15_000 });
}

async function createVehiculeInApp(
    page: Page,
    nomVehicule: string,
    immatriculation: string,
    typeVehiculeName?: string | RegExp,
): Promise<void> {
    await page.goto('/backoffice/vehicules/create');
    await expect(page).toHaveURL(/\/vehicules\/create$/, { timeout: 20_000 });

    await page.locator('#nom_vehicule').fill(nomVehicule);
    await page.locator('#immatriculation').fill(immatriculation);
    await selectOptionFromCombobox(
        page,
        page.locator('#type_vehicule'),
        typeVehiculeName,
    );
    if (
        await page
            .locator('#site_id')
            .isVisible()
            .catch(() => false)
    ) {
        await selectOptionFromCombobox(page, page.locator('#site_id'));
    }
    await page.getByRole('checkbox', { name: /livraison vente/i }).check();

    await page.getByTestId('vehicle-form-submit').click();
    // {20,} exclut "/vehicules/create" (qui matcherait [a-z0-9]+ puisque "create" est
    // lui-même tout en minuscules) — un ULID fait 26 caractères, cf. vehicule-flow.spec.ts.
    // Sans cette borne, waitForURL se résout immédiatement sur l'URL courante (déjà
    // "create") au lieu d'attendre la vraie navigation post-soumission, et la suite du test
    // navigue vers "/vehicules/create/edit" (404).
    await page.waitForURL(/\/vehicules\/[a-z0-9]{20,}$/, { timeout: 15_000 });
}

test.describe("Véhicule — dérogation au seuil d'impayés", () => {
    // Le contrôle (un Switch, cf. resources/js/components/ui/switch/Switch.vue) vit directement
    // sur la fiche véhicule (Vehicules/Show.vue) — plus sur Edit, où createVehiculeInApp()
    // atterrit déjà après création. Le plafond est désormais individuel au véhicule (décision
    // produit du 22/08/2026, plus jamais porté par le type) : activer le toggle révèle un champ
    // de saisie du plafond, confirmé par un bouton "Enregistrer" séparé — l'activation et le
    // plafond sont envoyés ensemble en une seule requête (VehiculeController::updateDerogation()).

    test('un véhicule ne peut pas activer la dérogation sans plafond renseigné', async ({
        page,
    }) => {
        await login(page);
        const unique = `${Date.now()}-${randomDigits(3)}`;

        await createVehiculeInApp(
            page,
            `${PREFIX} SansPlafond ${unique}`,
            `E2EIMP-A-${unique.slice(-5)}`,
        );
        await page.waitForLoadState('networkidle');

        const toggle = page.getByRole('switch', {
            name: /dérogation impayés/i,
        });
        await expect(toggle).toBeVisible({ timeout: 15_000 });
        await expect(toggle).toHaveAttribute('aria-checked', 'false');

        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-checked', 'true');

        const montantInput = page.locator('#seuil_derogation_impayes');
        await expect(montantInput).toBeVisible({ timeout: 10_000 });

        await page.getByRole('button', { name: /enregistrer/i }).click();

        // saveDerogation() (Vehicules/Show.vue) refuse localement, sans requête serveur, tant
        // qu'aucun plafond n'est saisi.
        await expect(
            page.getByText(/renseignez un plafond d'impayés autorisé/i),
        ).toBeVisible({ timeout: 10_000 });

        await page.reload();
        await expect(
            page.getByRole('switch', { name: /dérogation impayés/i }),
        ).toHaveAttribute('aria-checked', 'false', { timeout: 10_000 });
    });

    test('un véhicule avec un plafond renseigné peut activer la dérogation, et cela persiste après rechargement', async ({
        page,
    }) => {
        await login(page);
        const unique = `${Date.now()}-${randomDigits(3)}`;

        await createVehiculeInApp(
            page,
            `${PREFIX} AvecPlafond ${unique}`,
            `E2EIMP-B-${unique.slice(-5)}`,
        );
        await page.waitForLoadState('networkidle');

        const toggle = page.getByRole('switch', {
            name: /dérogation impayés/i,
        });
        await expect(toggle).toBeEnabled();
        await toggle.click();

        const montantInput = page.locator('#seuil_derogation_impayes');
        await expect(montantInput).toBeVisible({ timeout: 10_000 });
        await montantInput.click();
        await montantInput.pressSequentially('2000000');
        await montantInput.blur();
        // Formatage "2 000 000" pendant la saisie (Intl.NumberFormat('fr-FR'), espace fine
        // insécable U+202F entre les groupes) — même vérification que #seuil-impayes-input
        // dans vente-controle-impayes-flow.spec.ts ci-dessus.
        await expect(montantInput).toHaveValue(/^2\D000\D000$/);

        await page.getByRole('button', { name: /enregistrer/i }).click();

        await expect(page.locator('body')).toContainText(
            /dérogation impayés activée/i,
            { timeout: 10_000 },
        );
        await expect(toggle).toHaveAttribute('aria-checked', 'true');

        await page.reload();
        await expect(
            page.getByRole('switch', { name: /dérogation impayés/i }),
        ).toHaveAttribute('aria-checked', 'true', { timeout: 10_000 });
        await expect(page.locator('#seuil_derogation_impayes')).toHaveValue(
            /^2\D000\D000$/,
            { timeout: 10_000 },
        );
    });

    /**
     * Deux véhicules du MÊME type de véhicule, chacun avec un plafond dérogatoire propre :
     * confirme que la dérogation n'est jamais partagée au niveau du type (cf. TypeVehicules,
     * qui ne porte plus aucune notion d'impayés depuis le 22/08/2026).
     */
    test('deux véhicules du même type ont des plafonds dérogatoires indépendants', async ({
        page,
    }) => {
        await login(page);
        const unique = `${Date.now()}-${randomDigits(3)}`;
        const typeName = `${PREFIX} TypePartage ${unique}`;

        await createTypeVehiculeInApp(page, typeName);
        await createVehiculeInApp(
            page,
            `${PREFIX} Large ${unique}`,
            `E2EIMP-C-${unique.slice(-5)}`,
            typeName,
        );
        await page.waitForLoadState('networkidle');

        const toggle = page.getByRole('switch', {
            name: /dérogation impayés/i,
        });
        await toggle.click();
        const montantInput = page.locator('#seuil_derogation_impayes');
        await montantInput.click();
        await montantInput.pressSequentially('3000000');
        await montantInput.blur();
        await page.getByRole('button', { name: /enregistrer/i }).click();
        await expect(page.locator('body')).toContainText(
            /dérogation impayés activée/i,
            { timeout: 10_000 },
        );

        await createVehiculeInApp(
            page,
            `${PREFIX} Etroit ${unique}`,
            `E2EIMP-D-${unique.slice(-5)}`,
            typeName,
        );
        await page.waitForLoadState('networkidle');

        const toggle2 = page.getByRole('switch', {
            name: /dérogation impayés/i,
        });
        await toggle2.click();
        const montantInput2 = page.locator('#seuil_derogation_impayes');
        await montantInput2.click();
        await montantInput2.pressSequentially('500000');
        await montantInput2.blur();
        await page.getByRole('button', { name: /enregistrer/i }).click();
        await expect(page.locator('body')).toContainText(
            /dérogation impayés activée/i,
            { timeout: 10_000 },
        );

        await expect(page.locator('#seuil_derogation_impayes')).toHaveValue(
            /^500\D000$/,
            { timeout: 10_000 },
        );
    });
});

// ── Blocage réel d'une vente au-delà du seuil ───────────────────────────────
// Scénario porté sur une vente CLIENT (sans véhicule) : c'est le seul chemin qui facture
// immédiatement en IMPAYEE dès la création (CommandeVenteService::creerFactureDirecte()) —
// une vente rattachée à un véhicule ne bascule sa facture en IMPAYEE qu'à la validation du
// chargement, une étape supplémentaire hors du périmètre de ce scénario. La dérogation
// individuelle du véhicule (Vehicule::derogation_impayes_autorisee +
// Vehicule::seuil_derogation_impayes) applique EXACTEMENT la même règle de blocage —
// couverte en profondeur par tests/Feature/SolvabiliteImpayesTest.php et
// tests/Unit/SolvabiliteServiceTest.php.

async function createClientInApp(
    page: Page,
    nomComplet: string,
    tel: string,
): Promise<void> {
    await page.goto('/backoffice/clients/create');
    await page.locator('#nom_complet').fill(nomComplet);

    const paysCombo = page
        .locator('#client-form')
        .getByRole('combobox')
        .first();
    await selectOptionFromCombobox(page, paysCombo, /guin(?!.*bissau)/i);

    await page.locator('#telephone').fill(tel);
    await page
        .locator('#client-form button[type="submit"]:visible')
        .first()
        .click();
    await expect(page).toHaveURL(/\/clients\/[a-z0-9]+\/edit$/, {
        timeout: 15_000,
    });
}

/**
 * Sélectionne le client puis attend la résolution de l'appel check-solvabilite qu'il
 * déclenche (onClientSelect) — sans ce wait, les assertions suivantes (bouton
 * désactivé, message impayés) peuvent s'exécuter avant que `clientSolvabilite` ne soit
 * mis à jour côté client, où `blocked` retombe silencieusement sur `false` par défaut
 * (cf. Ventes/Create.vue, `clientSolvabilite.value?.blocked ?? false`).
 */
async function selectClientOnVenteForm(
    page: Page,
    nomComplet: string,
): Promise<void> {
    const input = page.getByPlaceholder('Nom, prénom, téléphone…');
    await input.click();
    await input.fill(nomComplet);
    const option = page
        .locator('[role="option"]:visible', { hasText: nomComplet })
        .first();
    await expect(option).toBeVisible({ timeout: 10_000 });

    const solvabiliteResponse = page.waitForResponse(
        (res) =>
            res.url().includes('/ventes/check-solvabilite') &&
            res.url().includes('client_id='),
        { timeout: 15_000 },
    );
    await option.click();
    await solvabiliteResponse;
}

test('vente client sous le seuil autorisée, la même dette dépassant un seuil abaissé bloque une nouvelle vente', async ({
    page,
}) => {
    await login(page);
    const unique = `${Date.now()}-${randomDigits(3)}`;
    const nomClient = `${PREFIX} Client ${unique}`;

    await createClientInApp(page, nomClient, randomDigits(9));

    // Seuil large : la première vente (qui facture immédiatement en IMPAYEE) doit passer.
    await setImpayesControle(page, true, 10_000_000);

    await page.goto('/backoffice/ventes/create');
    await expect(page).toHaveURL(/\/ventes\/create$/, { timeout: 20_000 });
    await selectClientOnVenteForm(page, nomClient);

    await page
        .locator('#vente-form button[type="submit"]:visible')
        .first()
        .click();

    // La soumission ouvre un dialog de confirmation — cliquer "Confirmer et créer" (cf.
    // facture-flow.spec.ts) : sans ce clic, form.post() ne part jamais et l'URL reste sur
    // "/ventes/create", ce que le regex ci-dessous matcherait quand même à tort ("create"
    // est alphanumérique) si on ne l'excluait pas explicitement.
    const confirmerEtCreerBtn = page.getByRole('button', {
        name: /confirmer et créer/i,
    });
    await expect(confirmerEtCreerBtn).toBeVisible({ timeout: 10_000 });
    await confirmerEtCreerBtn.click();
    await expect(page).toHaveURL(/\/ventes\/(?!create)[a-z0-9]+$/, {
        timeout: 15_000,
    });

    // Seuil abaissé à 0 : la dette de la vente précédente dépasse désormais le seuil — toute
    // nouvelle vente pour ce même client doit être bloquée.
    await setImpayesControle(page, true, 0);

    await page.goto('/backoffice/ventes/create');
    await expect(page).toHaveURL(/\/ventes\/create$/, { timeout: 20_000 });
    await selectClientOnVenteForm(page, nomClient);

    // "Commande bloquée — seuil dépassé" (blocage côté client, pas de véhicule sélectionné
    // ici) — les variantes véhicule ("— plafond dépassé" pour le seuil, "— première facture
    // non réglée" pour le nouveau verrou testé dans le describe ci-dessous) ne s'appliquent
    // pas à ce scénario, cf. Ventes/Create.vue.
    await expect(page.locator('body')).toContainText(/commande bloquée/i, {
        timeout: 10_000,
    });
    const submitBtn = page
        .locator('#vente-form button[type="submit"]:visible')
        .first();
    await expect(submitBtn).toBeDisabled({ timeout: 10_000 });
});

// ── Verrou « première régularisation » — véhicule (décision produit du 20/08/2026) ─────────
// Un véhicule ne peut pas cumuler plusieurs commandes ouvertes tant que la précédente n'a reçu
// aucun encaissement — indépendant du contrôle de seuil ci-dessus, cf. SolvabiliteService::
// premiereFactureNonEncaisseeVehicule(). Couverture détaillée (seuil, dérogation, isolation) dans
// tests/Unit/SolvabiliteServiceTest.php et tests/Feature/VehiculePremiereRegularisationTest.php ;
// ce scénario ne vérifie que le parcours utilisateur réel dans /backoffice/ventes/create.

/**
 * Un véhicule fraîchement créé reste `is_active=false` tant qu'aucune équipe ne lui est
 * assignée (VehiculeController::store() ; l'activation est un effet de bord de
 * EquipeLivraisonController::store(), cf. code source) — sans quoi il n'apparaîtrait jamais
 * dans la liste des véhicules actifs du formulaire de vente. Réutiliser un véhicule EXISTANT du
 * seed (« le premier de la liste ») a été essayé puis abandonné : l'environnement E2E est
 * partagé avec de nombreuses autres specs (achats, packing, produits, commissions…) qui créent
 * elles aussi des commandes sur des véhicules arbitraires, rendant le point de départ du test
 * imprévisible en CI. On crée donc un véhicule dédié PUIS on lui assigne l'équipe minimale
 * (1 chauffeur, commission 100 % à son nom — seule l'activation du véhicule nous intéresse ici,
 * pas la répartition elle-même) via le même flux UI qu'un administrateur suivrait réellement.
 */
async function activerVehiculeAvecEquipeMinimale(page: Page): Promise<void> {
    await page
        .locator('aside button')
        .filter({ hasText: /equipe/i })
        .click();

    // "Ajouter une équipe" (véhicule fraîchement créé, aucune équipe encore
    // assignée) — jamais "Gérer l'équipe", réservé aux véhicules qui en ont
    // déjà une (cf. Vehicules/Show.vue).
    const equipeBtn = page
        .getByRole('button', { name: /ajouter une équipe|gérer l'équipe/i })
        .first();
    await expect(equipeBtn).toBeVisible({ timeout: 10_000 });
    await equipeBtn.click();

    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /équipe/i });
    await expect(dialog).toBeVisible({ timeout: 10_000 });

    // Étape 1 (Membres) : un chauffeur, téléphone unique.
    await selectOptionFromCombobox(
        page,
        dialog.locator('[data-testid="role-dropdown-0"]'),
        /^chauffeur$/i,
    );
    const telInput = dialog.locator('[data-testid="telephone-0"]');
    await telInput.click();
    await telInput.pressSequentially(randomDigits(9), { delay: 20 });
    await dialog.getByRole('button', { name: /suivant/i }).click();

    // Étape 2 (Partage) : aucun barème de commission configuré pour ce
    // véhicule (organisation "elm", partagée avec de nombreuses autres specs
    // — aucun CommissionRegle n'y est seedé par défaut). L'étape affiche donc
    // l'état vide ; seule l'activation du véhicule nous intéresse ici, pas la
    // répartition elle-même (cf. commentaire de fonction ci-dessus).
    const suivantEtape2 = dialog.getByRole('button', { name: /suivant/i });
    await expect(suivantEtape2).toBeEnabled({ timeout: 5_000 });
    await suivantEtape2.click();

    // Étape 3 (Récapitulatif) : enregistrer.
    await dialog.getByRole('button', { name: /enregistrer l'équipe/i }).click();
    await expect(dialog).toBeHidden({ timeout: 15_000 });
}

/**
 * Ouvre le dropdown véhicule du formulaire de vente et sélectionne l'option dont le texte
 * contient `nomVehicule` — jamais en tapant dans le champ (fill() comme pressSequentially()
 * tronquent de façon reproductible le texte réellement saisi sur cet environnement, cf. analyse
 * du 20/08/2026 ; même contournement que facture-flow.spec.ts::selectFirstVehicule()).
 */
async function selectVehiculeOnVenteForm(
    page: Page,
    nomVehicule: string,
): Promise<void> {
    const vehiculeAutocomplete = page
        .locator('#vente-form .p-autocomplete')
        .first();
    await expect(vehiculeAutocomplete).toBeVisible({ timeout: 15_000 });
    await vehiculeAutocomplete
        .locator('button')
        .first()
        .click({ timeout: 5_000 });

    // hasText en chaîne (jamais une regex ici) : Playwright normalise déjà la casse/les
    // espaces, insensible à la capitalisation appliquée par le backend au nom du véhicule.
    const option = page
        .locator('[role="option"]:visible', { hasText: nomVehicule })
        .first();
    await expect(option).toBeVisible({ timeout: 10_000 });

    const solvabiliteResponse = page.waitForResponse(
        (res) =>
            res.url().includes('/ventes/check-solvabilite') &&
            res.url().includes('vehicule_id='),
        { timeout: 15_000 },
    );
    await option.click();
    await solvabiliteResponse;
}

test('un véhicule dont la commande précédente na reçu aucun paiement bloque une nouvelle commande', async ({
    page,
}) => {
    await login(page);
    const unique = `${Date.now()}-${randomDigits(3)}`;
    const nomVehicule = `${PREFIX} PremiereRegul ${unique}`;

    await createVehiculeInApp(
        page,
        nomVehicule,
        `E2EIMP-C-${unique.slice(-5)}`,
    );
    await activerVehiculeAvecEquipeMinimale(page);

    // ── 1. Première commande : passe en A_CHARGER, facture créée sans encaissement ──
    await page.goto('/backoffice/ventes/create');
    await expect(page).toHaveURL(/\/ventes\/create$/, { timeout: 20_000 });
    await selectVehiculeOnVenteForm(page, nomVehicule);

    const submit1 = page
        .locator('#vente-form button[type="submit"]:visible')
        .first();
    await expect(submit1).toBeEnabled({ timeout: 10_000 });
    await submit1.click();

    const confirmerEtCreerBtn = page.getByRole('button', {
        name: /confirmer et créer/i,
    });
    await expect(confirmerEtCreerBtn).toBeVisible({ timeout: 10_000 });
    await confirmerEtCreerBtn.click();
    await expect(page).toHaveURL(/\/ventes\/(?!create)[a-z0-9]+$/, {
        timeout: 30_000,
    });

    // ── 2. Deuxième commande pour le MÊME véhicule : doit être bloquée ──────────────
    await page.goto('/backoffice/ventes/create');
    await expect(page).toHaveURL(/\/ventes\/create$/, { timeout: 20_000 });
    await selectVehiculeOnVenteForm(page, nomVehicule);

    await expect(page.getByText(/première facture non réglée/i)).toBeVisible({
        timeout: 10_000,
    });
    await expect(page.locator('body')).toContainText(/aucun paiement/i, {
        timeout: 10_000,
    });

    const submit2 = page
        .locator('#vente-form button[type="submit"]:visible')
        .first();
    await expect(submit2).toBeDisabled({ timeout: 10_000 });
});
