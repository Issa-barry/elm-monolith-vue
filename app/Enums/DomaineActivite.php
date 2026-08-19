<?php

namespace App\Enums;

/**
 * Activité principale d'une organisation — saisie une fois à l'installation (CLI et web,
 * cf. InstallationService::install()), persistée sur organizations.domaine_activite comme
 * information métier durable. Sert à préconfigurer intelligemment l'organisation (catégories,
 * options, types de site suggérés — cf. CategorieDefaultSeeder, OptionCatalogueDefaultSeeder,
 * self::siteTypes()) sans jamais restreindre les types de produit (App\Enums\ProduitType reste
 * universel, cf. ProduitTypeDefaultSeeder) ni verrouiller ce que l'organisation peut faire
 * ensuite : ce n'est qu'un point de départ, pas une contrainte figée.
 */
enum DomaineActivite: string
{
    case COMMERCE_DISTRIBUTION = 'commerce_distribution';
    case INDUSTRIE_FABRICATION = 'industrie_fabrication';
    case RESTAURATION = 'restauration';
    case LOGISTIQUE_TRANSPORT = 'logistique_transport';
    case AUTRE = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::COMMERCE_DISTRIBUTION => 'Commerce & Distribution',
            self::INDUSTRIE_FABRICATION => 'Industrie, Fabrication & Artisanat',
            self::RESTAURATION => 'Restauration',
            self::LOGISTIQUE_TRANSPORT => 'Logistique & Transport',
            self::AUTRE => 'Autre activité',
        };
    }

    /**
     * Aide contextuelle affichée sous le libellé à l'étape "Domaine d'activité" du wizard —
     * quelques exemples concrets pour que l'utilisateur reconnaisse son métier sans avoir à
     * comprendre une taxonomie.
     */
    public function description(): string
    {
        return match ($this) {
            self::COMMERCE_DISTRIBUTION => 'Boutiques, magasins, quincailleries, commerces, revendeurs, distributeurs.',
            self::INDUSTRIE_FABRICATION => 'Usines, ateliers, artisans, entreprises qui fabriquent ou transforment des produits.',
            self::RESTAURATION => 'Restaurants et autres activités de restauration.',
            self::LOGISTIQUE_TRANSPORT => 'Transport, livraison, logistique, stockage et activités similaires.',
            self::AUTRE => 'Pour les entreprises qui ne correspondent pas aux domaines précédents.',
        };
    }

    /**
     * Types de site suggérés à l'onboarding du premier site (cf. OnboardingSiteController) —
     * une proposition adaptée au domaine, jamais une restriction : le CRUD Sites classique
     * (SiteController) reste libre de proposer tous les types, une organisation pouvant très
     * bien diversifier son activité après coup (ex: une boutique qui se met à fabriquer).
     *
     * @return array<int, SiteType>
     */
    public function siteTypes(): array
    {
        return match ($this) {
            self::COMMERCE_DISTRIBUTION => [SiteType::SIEGE, SiteType::BOUTIQUE, SiteType::DEPOT],
            self::INDUSTRIE_FABRICATION => [SiteType::SIEGE, SiteType::USINE, SiteType::DEPOT, SiteType::BOUTIQUE],
            self::RESTAURATION => [SiteType::SIEGE, SiteType::RESTAURANT, SiteType::DEPOT],
            self::LOGISTIQUE_TRANSPORT => [SiteType::SIEGE, SiteType::AGENCE, SiteType::DEPOT],
            self::AUTRE => [SiteType::SIEGE, SiteType::AGENCE, SiteType::AUTRE],
        };
    }

    /**
     * @return array<int, array{value: string, label: string, description: string, site_types: array<int, array{value: string, label: string}>}>
     */
    public static function options(): array
    {
        return array_map(fn ($c) => [
            'value' => $c->value,
            'label' => $c->label(),
            'description' => $c->description(),
            // Types de site suggérés pour ce domaine (cf. siteTypes()) — exposés ici pour que
            // l'étape "Site principal" du wizard d'installation (Install/Wizard.vue) puisse les
            // mettre en avant dès que le domaine est choisi, sans dupliquer cette liste côté front.
            'site_types' => array_map(fn (SiteType $t) => ['value' => $t->value, 'label' => $t->label()], $c->siteTypes()),
        ], self::cases());
    }
}
