<?php

namespace App\Support\Ventes;

use App\Enums\CategorieTarifaireVehicule;
use App\Enums\ClientType;
use App\Enums\CommissionRegleStatut;
use App\Enums\ModeRemiseGrossiste;
use App\Enums\ModeTarification;
use App\Enums\NatureOperation;
use App\Enums\PrixOrigine;
use App\Enums\ProduitStatut;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\ProduitVariante;
use App\Models\Site;
use App\Models\VarianteStock;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionPartageLivraisonCategorieChecker;
use App\Services\Commission\CommissionProcessusDefaults;
use App\Services\GrossisteTarifResolver;
use App\Services\PrixUsineResolver;
use App\Services\PrixVenteNatureResolver;
use App\Services\VehiculeCapaciteService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Chargement des options de formulaire (produits/véhicules/clients/site) et construction/
 * validation des lignes de commande — extrait de l'ancien `CommandeVenteController`, partagé par
 * `Ventes\{Create,Store,Edit,Update}CommandeVenteController`. Enregistre en un seul endroit toute
 * la logique jusque-là privée et partagée entre `create()`/`store()`/`edit()`/`update()` — aucune
 * règle métier modifiée, relocation pure (cf. docblocks d'origine conservés méthode par méthode).
 */
final class CommandeVenteFormBuilder
{
    private const MESSAGE_BLOCAGE_TOAST = 'Impossible de créer une commande : aucun stock disponible pour ce site.';

    private const LIGNES_REQUIRED_MESSAGE = 'Au moins une ligne de commande est requise.';

    private const UNIT_PRICE_UPDATE_PERMISSION = 'ventes.prix.update';

    public function __construct(private readonly VehiculeCapaciteService $vehiculeCapaciteService) {}

    public function getUserSite(): array
    {
        $site = $this->getUserSiteModel();

        return [
            'id' => $site->id,
            'nom' => $site->nom,
            'label' => ($site->type?->label() ?? '').' de '.$site->nom,
        ];
    }

    public function getUserSiteModel(): mixed
    {
        $site = auth()->user()
            ->sites()
            ->wherePivot('is_default', true)
            ->first(['sites.id', 'sites.nom', 'sites.type'])
            ?? auth()->user()->sites()->first(['sites.id', 'sites.nom', 'sites.type']);

        abort_if(! $site, 403, "Votre compte n'est rattaché à aucun site. Contactez votre administrateur.");

        return $site;
    }

    /**
     * Bloque la création d'une nouvelle commande vente quand la politique globale interdit la
     * vente sans stock ET que le site personnel de l'utilisateur n'a absolument aucun stock
     * vendable (cf. CommandeVenteService::siteAutoriseNouvelleCommande()). Appelé par create()
     * (accès direct à la page) et store() (POST direct) — même contrôle, jamais dupliqué en
     * logique, pour que désactiver le bouton côté Ventes/Index ne soit jamais la seule
     * protection. Ne renvoie jamais une page 403 : un accès direct malgré le blocage redirige
     * vers la liste des ventes avec un flash 'error', affiché en toast top-right côté
     * Ventes/Index.vue (règle projet : jamais de switch de position pour ce Toast).
     */
    public function redirectSiCreationBloquee(string $orgId, string $siteId): ?RedirectResponse
    {
        if (CommandeVenteService::siteAutoriseNouvelleCommande($orgId, $siteId)) {
            return null;
        }

        return redirect()->route('ventes.index')->with('error', self::MESSAGE_BLOCAGE_TOAST);
    }

    /**
     * $siteId : quand fourni ET que la politique globale interdit la vente sans stock
     * (Parametre::isVentesAutoriseesSansStock() = false), un produit géré en stock est exclu
     * de la liste si sa variante par défaut n'a AUCUN stock disponible sur CE site précis —
     * jamais sur l'agrégat global du produit (décision produit du 24/08/2026 : un stock
     * ailleurs ne doit jamais rendre visible un produit indisponible ici). $siteId omis
     * (edit() d'un brouillon existant) = aucun filtrage par stock, pour ne jamais faire
     * disparaître de la liste une ligne déjà existante dont le stock serait depuis tombé à 0.
     * Le formulaire ne propose pour l'instant qu'un sélecteur de produit (pas de sélecteur de
     * variante — Phase 3) : on filtre/affiche donc sur la variante par défaut (ou la première).
     */
    public function produitsActifs(string $orgId, ?string $siteId = null): Collection
    {
        $autoriseVenteStockNegatif = Parametre::isVentesAutoriseesSansStock($orgId);

        $produits = Produit::where('organization_id', $orgId)
            ->where('statut', ProduitStatut::ACTIF)
            ->whereHas('produitType', fn ($q) => $q->where('vendable', true))
            ->with(['variantes', 'produitType'])
            ->orderBy('nom')
            ->get();

        $varianteIds = $produits->flatMap(fn (Produit $p) => $p->variantes->pluck('id'))->all();
        // Disponible = physique − engagé (StockReservationService, 25/08/2026) : un produit
        // entièrement engagé par des commandes vente confirmées ne doit plus apparaître comme
        // sélectionnable ici, même si son stock physique brut reste positif.
        $stocksParVariante = $siteId
            ? VarianteStock::where('site_id', $siteId)->whereIn('produit_variante_id', $varianteIds)->get(['produit_variante_id', 'qte_stock', 'qte_reservee'])->keyBy('produit_variante_id')
            : collect();

        return $produits
            ->map(function (Produit $p) use ($stocksParVariante, $autoriseVenteStockNegatif, $siteId) {
                $variante = $p->variantes->firstWhere('is_default', true) ?? $p->variantes->first();
                $gereStock = (bool) $p->produitType?->gere_stock;

                if ($siteId && $gereStock && ! $autoriseVenteStockNegatif) {
                    $stock = $stocksParVariante[$variante?->id] ?? null;
                    $disponible = $stock ? ((int) $stock->qte_stock - (int) $stock->qte_reservee) : 0;
                    if ($disponible <= 0) {
                        return null;
                    }
                }

                return [
                    'id' => $p->id,
                    'nom' => $p->nom,
                    'categorie_id' => $p->categorie_id,
                    'prix_vente' => (int) ($variante?->prix_vente ?? 0),
                    'prix_usine' => (int) ($variante?->prix_usine ?? 0),
                    // Tarification par nature de client (cf. PrixVenteNatureResolver) — pilote
                    // le recalcul live du "Prix appliqué" dans Ventes/Create.vue/Edit.vue dès
                    // qu'un client est sélectionné. Réservée aux produits fabricables.
                    'is_fabricable' => $p->produitType?->code === 'fabricable',
                    'prix_externe' => $variante?->prix_externe,
                    'prix_revendeur' => $variante?->prix_revendeur,
                    'prix_distributeur' => $variante?->prix_distributeur,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Véhicules sélectionnables pour une vente standard — inchangé par le chantier « distribution
     * client » du 31/08/2026 (cf. vehiculesLogistiques() pour le pool séparé, exclusif à la
     * distribution).
     */
    public function vehiculesActifs(string $orgId): Collection
    {
        return $this->vehiculesEligibles($orgId, fn ($q) => $q->livraisonVente());
    }

    /**
     * Véhicules sélectionnables pour une distribution client — autorisés pour l'usage logistique
     * (Vehicule::livraison_logistique = true), jamais les véhicules vente-only. Pool séparé de
     * vehiculesActifs() ci-dessus : un même véhicule peut apparaître dans les deux si son
     * organisation l'a explicitement autorisé pour les deux usages, mais jamais un véhicule
     * exclusivement vente n'apparaît ici, ni l'inverse.
     */
    public function vehiculesLogistiques(string $orgId): Collection
    {
        return $this->vehiculesEligibles($orgId, fn ($q) => $q->livraisonLogistique());
    }

    /** @param  callable(Builder): Builder  $scopeUsage */
    private function vehiculesEligibles(string $orgId, callable $scopeUsage): Collection
    {
        $query = Vehicule::with([
            'typeVehicule',
            'capacites.categorie',
            'equipe.livreurs' => fn ($q) => $q->wherePivot('role', 'chauffeur'),
            'equipe.membres.livreur',
        ])
            ->where('organization_id', $orgId)
            ->where('is_active', true);

        $scopeUsage($query);

        return $query
            ->orderBy('nom_vehicule')
            ->get()
            ->map(fn (Vehicule $v) => $this->mapVehiculeOption($v));
    }

    private function mapVehiculeOption(Vehicule $v): array
    {
        return [
            'id' => $v->id,
            'nom_vehicule' => $v->nom_vehicule,
            'immatriculation' => $v->immatriculation,
            'type_vehicule_nom' => $v->typeVehicule?->nom,
            // Plafonds par catégorie de produit (Sachet eau, Bouteille, ...), propres à ce
            // véhicule — aucun héritage depuis le type, même calcul que le contrôle serveur
            // (VehiculeCapaciteService::capacitesParCategorie), pour que le frontend affiche
            // exactement ce que le backend va vérifier. Vide = véhicule non limité.
            'capacites' => $this->vehiculeCapaciteService->capacitesParCategorieAvecNoms($v),
            'livreur_nom' => $v->equipe?->livreurs->first()?->libelleAffichage(),
            'livreur_telephone' => $v->equipe?->membres
                ->firstWhere('role', 'chauffeur')
                ?->livreur?->telephone,
            'equipe_membres' => $v->equipe?->membres
                ->map(fn ($membre) => [
                    'id' => $membre->id,
                    'nom' => $membre->livreur?->libelleAffichage() ?? 'Livreur',
                    'telephone' => $membre->livreur?->telephone,
                    'role' => $membre->role,
                ])
                ->values() ?? [],
        ];
    }

    public function clientsActifs(string $orgId): Collection
    {
        return Client::where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('nom_complet')
            ->get()
            ->map(fn (Client $c) => [
                'id' => $c->id,
                'nom_complet' => $c->nom_complet,
                'telephone' => $c->telephone,
                'type' => $c->type->value,
                'type_label' => $c->type->label(),
                // Véhicules externes mémorisés — facultatifs, jamais un prérequis pour vendre
                // à ce client (cf. ClientVehicle).
                'vehicules' => $c->type === ClientType::EXTERNE
                    ? $c->vehicules()->get()->map(fn ($cv) => [
                        'id' => $cv->id,
                        'libelle_affiche' => $cv->libelle_affiche,
                    ])->values()
                    : [],
            ]);
    }

    public function commandeValidationRules(): array
    {
        return [
            'vehicule_id' => 'nullable|exists:vehicules,id',
            'client_id' => 'nullable|exists:clients,id',
            'nature_operation' => ['nullable', Rule::in(NatureOperation::values())],
            // mode_remise_grossiste n'est PLUS un champ soumis (décision produit du 05/09/2026,
            // révision UX) : dérivé côté serveur depuis vehicule_id, cf. deriverModeRemiseGrossiste()
            // — jamais une seconde information indépendante saisie par l'utilisateur, pour éviter
            // toute incohérence Enlèvement+véhicule / Livraison+sans véhicule par construction.
            // Véhicule partenaire facultatif — jamais un substitut à vehicule_id (flotte gérée),
            // cf. ClientVehicle. Doit appartenir au client sélectionné.
            'client_vehicule_id' => [
                'nullable',
                Rule::exists('client_vehicules', 'id')->where(function ($q) {
                    $q->where('client_id', request()->input('client_id'));
                }),
            ],
            'lignes' => 'required|array|min:1',
            'lignes.*.produit_id' => 'required|exists:produits,id',
            // Optionnel : le formulaire actuel ne sélectionne qu'un produit (pas encore de
            // sélecteur de variante — Phase 3). resolveVariante() retombe sur la variante
            // par défaut du produit si absent.
            'lignes.*.variante_id' => 'nullable|exists:produit_variantes,id',
            'lignes.*.qte' => 'required|integer|min:1',
            'lignes.*.prix_vente' => 'required|numeric|min:0',
        ];
    }

    public function commandeValidationMessages(): array
    {
        return [
            'lignes.required' => self::LIGNES_REQUIRED_MESSAGE,
            'lignes.min' => self::LIGNES_REQUIRED_MESSAGE,
            'lignes.*.produit_id.required' => 'Le produit est obligatoire pour chaque ligne.',
            'lignes.*.produit_id.exists' => 'Le produit sélectionné est introuvable.',
            'lignes.*.qte.required' => 'La quantité est obligatoire pour chaque ligne.',
            'lignes.*.qte.min' => 'La quantité doit être supérieure à 0.',
            'lignes.*.prix_vente.required' => 'Le prix de vente est obligatoire pour chaque ligne.',
            'lignes.*.prix_vente.min' => 'Le prix de vente ne peut pas être négatif.',
        ];
    }

    public function ensureVehiculeOrClientSelected(array $data): void
    {
        if (! empty($data['vehicule_id']) || ! empty($data['client_id'])) {
            return;
        }

        throw ValidationException::withMessages([
            'vehicule_id' => 'Veuillez sélectionner un véhicule ou un client.',
            'client_id' => 'Veuillez sélectionner un véhicule ou un client.',
        ]);
    }

    /**
     * mode_remise_grossiste est PAR COMMANDE, jamais une caractéristique du client (cf.
     * docs/grossiste.md) — mais DEPUIS le 05/09/2026, plus une seconde information saisie par
     * l'utilisateur : dérivée uniquement de la présence d'un véhicule, seule source de vérité.
     * Véhicule sélectionné ⇒ Livraison, aucun véhicule ⇒ Enlèvement. Élimine par construction
     * toute incohérence Enlèvement+véhicule / Livraison+sans véhicule — il n'existe plus de
     * champ indépendant à valider. Null pour tout client non-Grossiste (notion sans objet).
     */
    public function deriverModeRemiseGrossiste(?string $vehiculeId, ?Client $client): ?ModeRemiseGrossiste
    {
        if ($client?->type !== ClientType::GROSSISTE) {
            return null;
        }

        return $vehiculeId ? ModeRemiseGrossiste::LIVRAISON : ModeRemiseGrossiste::ENLEVEMENT;
    }

    /**
     * Charge le véhicule une seule fois par requête (store/update), scopé à l'organisation
     * courante — jamais l'ID brut non vérifié — avec son équipe et son chauffeur actif
     * eager-chargés. Réutilisé par la dérivation de nature_operation, sa validation de
     * cohérence, et le pré-contrôle de partage commission : jamais trois requêtes séparées.
     */
    public function resolveVehiculeAvecEquipe(?string $vehiculeId, string $orgId): ?Vehicule
    {
        if (empty($vehiculeId)) {
            return null;
        }

        return Vehicule::query()
            ->with(['equipe.livreurs' => fn ($q) => $q->wherePivot('role', 'chauffeur')])
            ->where('organization_id', $orgId)
            ->find($vehiculeId);
    }

    /**
     * Backend, source de vérité — le frontend filtre déjà la liste des véhicules et désactive
     * l'option quand elle n'est pas disponible, mais la règle métier ne doit jamais reposer
     * uniquement sur le formulaire (contournement possible via API/requête forgée). Révisé le
     * 31/08/2026 : vérifiait auparavant seulement la présence d'un véhicule, jamais son usage
     * autorisé ni la présence d'un livreur assigné.
     *
     * $vehicule doit déjà être scopé à l'organisation courante (cf. resolveVehiculeAvecEquipe())
     * — un véhicule non trouvé ($vehicule === null alors que $vehiculeId est renseigné) signifie
     * donc soit un id inexistant, soit un véhicule d'une autre organisation : les deux cas
     * doivent être rejetés de la même façon, jamais une fuite d'information sur l'existence
     * réelle du véhicule dans une autre organisation.
     */
    public function ensureNatureOperationCoherente(NatureOperation $natureOperation, ?string $vehiculeId, ?Vehicule $vehicule): void
    {
        if ($natureOperation !== NatureOperation::DISTRIBUTION_CLIENT) {
            return;
        }

        if (empty($vehiculeId)) {
            throw ValidationException::withMessages([
                'nature_operation' => 'Une distribution client nécessite un véhicule de livraison.',
            ]);
        }

        if (! $vehicule) {
            throw ValidationException::withMessages([
                'vehicule_id' => 'Ce véhicule est introuvable pour votre organisation.',
            ]);
        }

        if (! $vehicule->is_active) {
            throw ValidationException::withMessages([
                'vehicule_id' => "Ce véhicule n'est plus actif.",
            ]);
        }

        if (! $vehicule->livraison_logistique) {
            throw ValidationException::withMessages([
                'vehicule_id' => "Ce véhicule n'est pas autorisé pour la distribution (usage logistique requis).",
            ]);
        }

        $aUnLivreurActif = $vehicule->equipe?->is_active
            && $vehicule->equipe->livreurs->contains(fn ($l) => $l->is_active);

        if (! $aUnLivreurActif) {
            throw ValidationException::withMessages([
                'vehicule_id' => "Ce véhicule n'a aucun livreur actif assigné — une distribution nécessite un livreur.",
            ]);
        }
    }

    public function ensureQuantiteMatchesVehiculeCapacity(array $data): void
    {
        if (empty($data['vehicule_id'])) {
            return;
        }

        $vehicule = Vehicule::query()->find($data['vehicule_id']);
        if (! $vehicule) {
            return;
        }

        $orgId = auth()->user()->organization_id;

        $this->vehiculeCapaciteService->verifier(
            $vehicule,
            $data['lignes'] ?? [],
            'qte',
            ! Parametre::isVentesAutorisationSaisieDessousQteMax($orgId),
        );
    }

    /**
     * Garde-fou préventif — jamais un remplacement du filet de sécurité de la génération
     * (CommissionEnveloppeGenerator, différée au déclencheur configuré par l'organisation, cf.
     * CommissionTriggerService) : réduit le risque qu'une commande apparaisse "payée" mais reste
     * bloquée "à régulariser" faute de partage Livreur configuré pour une catégorie vendue (cf.
     * incident CMD-300826-007, 30/08/2026). La configuration de partage peut encore changer entre
     * cette création et la génération réelle — ce contrôle réduit le risque, il ne l'élimine pas.
     *
     * Hors périmètre volontairement : véhicule sans équipe de livraison du tout pour la cible
     * Livreur (erreur distincte, déjà portée par le générateur — sauf pour Transfert grossiste,
     * dont l'absence TOTALE de barème est vérifiée indépendamment de l'équipe, cf.
     * ensureTransfertGrossisteBaremeConfigure()) et véhicule non éligible pour l'usage réellement
     * concerné (commission_eligible_snapshot resterait false, la génération ne tente jamais de
     * résoudre le partage Livreur pour ce véhicule) — la vérification d'usage autorisé reflète
     * exactement celle de VehiculeCommandeContextResolver::resolve().
     *
     * $vehicule et $natureOperation doivent être ceux déjà résolus par resolveVehiculeAvecEquipe()/
     * resoudreNatureOperation() — jamais un second calcul indépendant qui pourrait diverger.
     * $clientType/$modeRemiseGrossiste sont nécessaires depuis le 05/09/2026 (chantier « Transfert
     * grossiste ») pour résoudre la même identité de processus que le générateur réel, cf.
     * CommissionProcessusDefaults::identiteCodePourVente().
     */
    public function ensurePartageLivraisonCategorieConfigure(
        NatureOperation $natureOperation,
        ?Vehicule $vehicule,
        array $lignes,
        ?ClientType $clientType = null,
        ?ModeRemiseGrossiste $modeRemiseGrossiste = null,
    ): void {
        if (! $vehicule) {
            return;
        }

        // Résolution IDENTIQUE à celle du générateur réel (CommissionEnveloppeGenerator::
        // genererPourCommandeVente()) — jamais un second calcul indépendant qui pourrait diverger.
        $identiteCode = CommissionProcessusDefaults::identiteCodePourVente($natureOperation, $clientType, $modeRemiseGrossiste);

        $usageAutorise = CommissionProcessusDefaults::estApplicablePourVehicule($identiteCode, $vehicule);
        if (! $usageAutorise) {
            return;
        }

        $organizationId = auth()->user()->organization_id;
        // L'identité (distribution_client/vente/transfert_grossiste) reste toujours celle de la
        // commande — c'est elle qui sera écrite sur la CommissionEnveloppe générée. Le garde-fou
        // doit en revanche vérifier le barème RÉELLEMENT consommé par le générateur, qui peut
        // différer de l'identité (cf. CommissionProcessusDefaults::processusResolutionBareme(),
        // décision produit du 02/09/2026 : distribution_client retombe sur le barème de
        // logistique_transfert tant qu'il n'a pas sa propre CommissionRegle active —
        // transfert_grossiste n'a en revanche AUCUN repli, cf. ci-dessous). Sans cette résolution
        // identique à celle du générateur, ce contrôle préventif pourrait valider un partage qui ne
        // sera jamais celui réellement consommé, ou en exiger un que le générateur ne lira jamais.
        $processusIdentite = CommissionProcessusDefaults::resoudreOuCreer($organizationId, $identiteCode);
        $processusBareme = CommissionProcessusDefaults::processusResolutionBareme($processusIdentite);

        // Transfert grossiste : décision produit du 05/09/2026 (cf. docs/grossiste.md) — jamais de
        // repli de barème (contrairement à distribution_client). Sans ce contrôle, une organisation
        // n'ayant configuré AUCUNE CommissionRegle pour ce processus verrait une livraison Grossiste
        // générer silencieusement 0 commission sur TOUTES les cibles (Propriétaire/Livreur/Site/
        // Consultant), y compris celles qui ne dépendent pas d'une équipe. Vérifié indépendamment
        // de la présence d'une équipe (contrairement au reste de cette méthode) pour couvrir aussi
        // ce cas.
        if ($identiteCode === CommissionProcessus::CODE_TRANSFERT_GROSSISTE) {
            $this->ensureTransfertGrossisteBaremeConfigure($organizationId, $processusBareme);
        }

        if (! $vehicule->equipe) {
            return;
        }

        $categorieIds = CommissionPartageLivraisonCategorieChecker::categorieIdsDepuisLignes($lignes);

        $manquantes = CommissionPartageLivraisonCategorieChecker::categoriesManquantes(
            $organizationId,
            $vehicule->equipe->id,
            $processusBareme->code,
            $vehicule->type_vehicule_id,
            $categorieIds,
            Carbon::today(),
        );

        if ($manquantes->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'vehicule_id' => sprintf(
                'Le véhicule %s n\'a pas de partage de commission configuré pour le processus « %s » sur : %s. Configurez la répartition de l\'équipe avant de continuer.',
                $vehicule->nom_vehicule,
                $processusIdentite->libelle,
                $manquantes->pluck('nom')->implode(', '),
            ),
        ]);
    }

    /**
     * Garde-fou préventif spécifique à Transfert grossiste (décision produit du 05/09/2026, cf.
     * docs/grossiste.md) — contrairement à distribution_client, ce processus n'a AUCUN repli
     * automatique de barème (CommissionProcessusDefaults::processusResolutionBareme() ne le
     * concerne pas) : une organisation n'ayant configuré AUCUNE CommissionRegle active pour ce
     * processus verrait sinon une livraison Grossiste générer silencieusement 0 commission sur
     * toutes les cibles (décision AMOA #4, techniquement correcte mais dangereuse pour un flux
     * récurrent et non un cas isolé). Bloque donc explicitement, avec un message actionnable,
     * plutôt que de laisser passer silencieusement.
     *
     * Volontairement un contrôle "au moins une règle existe pour ce processus" — jamais un contrôle
     * par catégorie/cible : l'indépendance des cibles (chantier 2A) reste entière, un barème
     * PARTIELLEMENT configuré (ex: Site seul) ne bloque jamais — seule l'ABSENCE TOTALE de
     * configuration est un blocage, exactement le scénario "personne n'a encore ouvert l'onglet
     * Transferts grossistes dans Paramètres > Commissions".
     */
    private function ensureTransfertGrossisteBaremeConfigure(string $organizationId, CommissionProcessus $processusBareme): void
    {
        $configure = CommissionRegle::where('organization_id', $organizationId)
            ->where('processus_id', $processusBareme->id)
            ->where('statut', CommissionRegleStatut::ACTIVE->value)
            ->exists();

        if ($configure) {
            return;
        }

        throw ValidationException::withMessages([
            'vehicule_id' => 'Aucun barème « Transfert grossiste » n\'est configuré pour votre organisation. '
                .'Configurez les commissions dans Paramètres > Commissions (onglet Transferts grossistes) '
                .'avant de valider une livraison Grossiste.',
        ]);
    }

    /**
     * Résout le client une seule fois par requête (store/update), réutilisé par
     * enforcePrixVentePolicy() ET buildLignesDataAndTotal() — jamais un second aller-retour DB
     * pour la même commande. Sélection minimale ('id', 'type') : c'est tout ce dont
     * PrixVenteNatureResolver a besoin.
     */
    public function resolveClientForTarification(?string $clientId): ?Client
    {
        return $clientId ? Client::query()->select(['id', 'type'])->find($clientId) : null;
    }

    public function enforcePrixVentePolicy(array $data, ?CommandeVente $commande, ?Client $client): void
    {
        if (auth()->user()->can(self::UNIT_PRICE_UPDATE_PERMISSION)) {
            return;
        }

        $lignes = collect($data['lignes'] ?? []);
        if ($lignes->isEmpty()) {
            return;
        }

        $existingPrixParVariante = $this->existingPrixVenteByVariante($commande);

        foreach ($data['lignes'] as $index => $ligne) {
            $variante = $this->resolveVariante($ligne);

            // Ligne fabricable avec client : le prix effectivement facturé vient de
            // PrixVenteNatureResolver (cf. buildLignesDataAndTotal()), pas de ce qui est soumis
            // ici — aucune valeur reçue n'est donc jamais réellement utilisée pour cette ligne,
            // ce contrôle anti-manipulation n'a plus d'objet.
            if (PrixVenteNatureResolver::estFabricable($variante) && $client) {
                continue;
            }

            $prixRecu = (float) ($ligne['prix_vente'] ?? 0);
            $prixAttendu = $existingPrixParVariante[$variante->id] ?? (float) ($variante->prix_vente ?? $prixRecu);

            if (abs($prixRecu - $prixAttendu) > 0.00001) {
                throw ValidationException::withMessages([
                    "lignes.{$index}.prix_vente" => 'Vous n\'etes pas autorisé à modifier le prix unitaire.',
                ]);
            }
        }
    }

    private function existingPrixVenteByVariante(?CommandeVente $commande): array
    {
        if (! $commande) {
            return [];
        }

        $commande->loadMissing('lignes');

        return $commande->lignes
            ->groupBy('variante_id')
            ->map(fn ($lignes): float => (float) $lignes->first()->prix_vente_snapshot)
            ->toArray();
    }

    public function buildLignesDataAndTotal(array $lignes, ModeTarification $mode, ?CategorieTarifaireVehicule $categorieTarifaire = null, ?Client $client = null, ?ModeRemiseGrossiste $modeRemiseGrossiste = null): array
    {
        $lignesData = [];
        $totalCommande = 0;

        foreach ($lignes as $ligne) {
            $variante = $this->resolveVariante($ligne);
            $produit = $variante->produit;
            $qte = (int) $ligne['qte'];

            // Grossiste : tarif catégorie × mode × client (GrossisteTarifResolver), gouverne SEUL
            // le total de la ligne — jamais PrixVenteNatureResolver/PrixUsineResolver/
            // ModeTarification, qui ne s'appliquent pas à cette nature (cf. docs/grossiste.md).
            // $modeRemiseGrossiste est dérivé plus haut (deriverModeRemiseGrossiste()) depuis
            // vehicule_id, toujours non-null ici pour un client Grossiste. Le tarif spécial est une
            // surcharge facultative : repli automatique sur prix_vente si absent (resolveOrigine()
            // le reflète dans prix_origine_snapshot pour rester transparent à l'affichage).
            if ($client?->type === ClientType::GROSSISTE && $modeRemiseGrossiste) {
                $prixGrossiste = (float) GrossisteTarifResolver::resolve($variante, $modeRemiseGrossiste, $client);
                $origineGrossiste = GrossisteTarifResolver::resolveOrigine($variante, $modeRemiseGrossiste, $client);
                $totalLigneGrossiste = $qte * $prixGrossiste;

                $lignesData[] = [
                    'variante_id' => $variante->id,
                    'quantite_demandee' => $qte,
                    'prix_usine_snapshot' => $prixGrossiste,
                    'prix_vente_snapshot' => $prixGrossiste,
                    'prix_origine_snapshot' => $origineGrossiste->value,
                    'total_ligne' => $totalLigneGrossiste,
                    'libelle_snapshot' => $this->libelleSnapshot($produit, $variante),
                ];

                $totalCommande += $totalLigneGrossiste;

                continue;
            }

            // Fabricable + client : le prix par nature de client (Externe/Revendeur/
            // Distributeur) remplace le prix de vente saisi/existant — jamais l'inverse (cf.
            // enforcePrixVentePolicy() qui n'a alors plus rien à valider pour cette ligne) — et
            // gouverne SEUL le total de cette ligne, sans passer par le mode de tarification
            // véhicule/client (qui basculerait sinon un client Externe entier sur prix_usine,
            // ignorant le prix_externe qu'on vient de résoudre). Produit non-fabricable ou
            // aucun client : comportement historique inchangé (mode global).
            $ligneFabricablePourClient = PrixVenteNatureResolver::estFabricable($variante) && $client;
            $prixVente = $ligneFabricablePourClient
                ? (float) PrixVenteNatureResolver::resolve($variante, $client)
                : (float) $ligne['prix_vente'];
            $prixUsine = (float) PrixUsineResolver::resolve($variante, $categorieTarifaire);
            $appliquerPrixVente = $ligneFabricablePourClient || $mode === ModeTarification::PRIX_VENTE;
            $totalLigne = $qte * ($appliquerPrixVente ? $prixVente : $prixUsine);
            $prixOrigine = $ligneFabricablePourClient
                ? PrixVenteNatureResolver::resolveOrigine($variante, $client)
                : ($appliquerPrixVente ? PrixOrigine::VENTE : PrixOrigine::USINE);

            $lignesData[] = [
                'variante_id' => $variante->id,
                'quantite_demandee' => $qte,
                'prix_usine_snapshot' => $prixUsine,
                'prix_vente_snapshot' => $prixVente,
                'prix_origine_snapshot' => $prixOrigine->value,
                'total_ligne' => $totalLigne,
                'libelle_snapshot' => $this->libelleSnapshot($produit, $variante),
            ];

            $totalCommande += $totalLigne;
        }

        return [$lignesData, $totalCommande];
    }

    /**
     * Résout la variante réellement vendue à partir d'une ligne saisie. Le formulaire actuel
     * ne propose qu'un sélecteur de produit (pas encore de sélecteur de variante — Phase 3) :
     * si le produit n'a qu'une seule variante (cas normal, produit "simple"), on la prend
     * directement ; sinon on exige que variante_id soit explicitement fourni plutôt que de
     * deviner laquelle vendre.
     */
    private function resolveVariante(array $ligne): ProduitVariante
    {
        if (! empty($ligne['variante_id'])) {
            return ProduitVariante::with('produit.produitType')->findOrFail($ligne['variante_id']);
        }

        $produit = Produit::with(['variantes', 'produitType'])->findOrFail($ligne['produit_id']);

        if ($produit->variantes->count() === 1) {
            return $produit->variantes->first();
        }

        if ($produit->variantes->count() > 1) {
            throw ValidationException::withMessages([
                'lignes' => "Le produit « {$produit->nom} » a plusieurs déclinaisons — précisez la variante à vendre.",
            ]);
        }

        throw ValidationException::withMessages([
            'lignes' => "Le produit « {$produit->nom} » n'a aucune variante disponible.",
        ]);
    }

    private function libelleSnapshot(Produit $produit, ProduitVariante $variante): string
    {
        return $variante->libelle !== '' ? "{$produit->nom} — {$variante->libelle}" : $produit->nom;
    }

    /**
     * Contrôle de disponibilité au moment de CRÉER ou MODIFIER une commande (24/08/2026) —
     * avant ce correctif, une commande pouvait être créée avec une quantité supérieure au
     * stock, le seul contrôle existant intervenait au chargement (cf. CommandeVenteService::
     * checkDisponibiliteStock()). Délègue entièrement à CommandeVenteService::
     * verifierDisponibiliteLignes() — jamais de logique dupliquée ici, ce contrôleur ne fait
     * que traduire le résultat en ValidationException affichée dans le formulaire. $lignesData
     * est le format déjà produit par buildLignesDataAndTotal() (variante_id résolu +
     * quantite_demandee), jamais recalculé.
     *
     * @param  array<int, array{variante_id: string, quantite_demandee: int}>  $lignesData
     *
     * @throws ValidationException si au moins une ligne dépasse le disponible
     */
    public function assertStockDisponiblePourLignes(string $orgId, string $siteId, array $lignesData): void
    {
        $errors = [];

        CommandeVenteService::verifierDisponibiliteLignes(
            $orgId,
            $siteId,
            array_map(fn (array $l) => ['variante_id' => $l['variante_id'], 'quantite' => $l['quantite_demandee']], $lignesData),
            $errors,
        );

        if (! empty($errors)) {
            throw ValidationException::withMessages(['lignes' => $errors]);
        }
    }

    public function commandeSnapshot(CommandeVente $commande): array
    {
        return [
            'vehicule_id' => $commande->vehicule_id,
            'vehicule_nom' => $commande->vehicule?->nom_vehicule,
            'client_id' => $commande->client_id,
            'client_nom' => $commande->client?->nom_complet,
            'total_commande' => (float) $commande->total_commande,
            'mode_tarification_snapshot' => $commande->mode_tarification_snapshot?->value,
            'commission_eligible_snapshot' => (bool) $commande->commission_eligible_snapshot,
            'nature_operation' => $commande->nature_operation?->value,
            'mode_remise_grossiste' => $commande->mode_remise_grossiste?->value,
            'statut' => $commande->statut?->value,
            'lignes' => $commande->lignes->map(fn ($l) => [
                'variante_id' => $l->variante_id,
                'produit_nom' => $l->libelle_snapshot ?? $l->variante?->produit?->nom,
                'quantite_demandee' => (int) $l->quantite_demandee,
                'prix_vente_snapshot' => (float) $l->prix_vente_snapshot,
                'total_ligne' => (float) $l->total_ligne,
            ])->values()->all(),
        ];
    }
}
