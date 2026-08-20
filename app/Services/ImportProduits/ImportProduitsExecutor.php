<?php

namespace App\Services\ImportProduits;

use App\Enums\AuditEvent;
use App\Models\ImportProduits;
use App\Models\Produit;
use App\Models\ProduitVariante;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\ProduitService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Exécute la création/mise à jour réelle des produits à partir d'un ImportProduits déjà
 * analysé. Tout-ou-rien : si une seule ligne est en erreur (ou si le fichier est signalé comme
 * déjà importé, cf. ImportProduitsParser), rien n'est enregistré.
 *
 * Ré-analyse intégralement le fichier stocké à l'instant T (jamais l'aperçu déjà affiché à
 * l'utilisateur) — même garde-fou qu'ImportFlotteExecutor/SiteImportExecutor. La création passe
 * par ProduitService::creer(), la mise à jour par ProduitService::mettreAJourSimple() : jamais
 * de logique métier dupliquée ici, cet exécuteur ne fait que router les lignes déjà classées et
 * verrouiller/auditer autour de l'appel au service.
 */
class ImportProduitsExecutor
{
    public function __construct(
        private readonly ImportProduitsParser $parser,
        private readonly ProduitService $produitService,
        private readonly AuditLogService $auditService,
    ) {}

    public function executer(ImportProduits $import, User $actor): array
    {
        $absolutePath = Storage::disk('local')->path($import->fichier_path);

        // Intégrité : le fichier stocké doit être exactement celui analysé au store() — un
        // écart signale une altération hors application entre l'aperçu et la confirmation.
        $hashActuel = hash_file('sha256', $absolutePath) ?: null;
        if ($import->fichier_hash !== null && $hashActuel !== $import->fichier_hash) {
            return [
                'succes' => false,
                'raison' => 'integrite',
                'rapport' => null,
            ];
        }

        $analyse = $this->parser->analyserFichier($absolutePath, $import->organization_id);

        $lignesErreur = array_filter($analyse['lignes'], fn ($l) => $l['statut'] === 'erreur');
        if (! empty($lignesErreur) || ! empty($analyse['fichier_deja_importe'])) {
            return [
                'succes' => false,
                'raison' => ! empty($analyse['fichier_deja_importe']) ? 'fichier_deja_importe' : 'erreurs',
                'rapport' => $analyse,
            ];
        }

        // Contrôle de concurrence : le produit/la variante ciblés ont-ils changé depuis l'aperçu
        // affiché à l'utilisateur (import manuel concurrent, autre import, etc.) ? On compare la
        // classification + le diff FRAÎCHEMENT recalculés à ceux déjà stockés dans $import->rapport
        // (capturé au store(), donc jamais touché par CETTE ré-analyse) — jamais un diff différent
        // de celui revu par l'utilisateur appliqué à son insu.
        if ($this->apercuAChange($import->rapport['lignes'] ?? [], $analyse['lignes'])) {
            return [
                'succes' => false,
                'raison' => 'apercu_perime',
                'rapport' => $analyse,
            ];
        }

        // Permissions différenciées, vérifiées AVANT toute écriture — sur la classification
        // FRAÎCHE (post ré-analyse), pas sur l'aperçu affiché initialement à l'utilisateur.
        $auMoinsUneCreation = collect($analyse['lignes'])->contains(fn ($l) => $l['statut'] === 'creation');
        $auMoinsUneMiseAJour = collect($analyse['lignes'])->contains(fn ($l) => $l['statut'] === 'mise_a_jour');

        if ($auMoinsUneCreation && ! $actor->can('produits.create')) {
            return ['succes' => false, 'raison' => 'permission_create', 'rapport' => $analyse];
        }
        if ($auMoinsUneMiseAJour && ! $actor->can('produits.update')) {
            return ['succes' => false, 'raison' => 'permission_update', 'rapport' => $analyse];
        }

        $compteurs = ['crees' => 0, 'mis_a_jour' => 0, 'inchanges' => 0];
        $rapportFinal = $analyse;

        DB::transaction(function () use ($import, $actor, &$compteurs, &$rapportFinal) {
            foreach ($rapportFinal['lignes'] as $index => &$ligne) {
                match ($ligne['statut']) {
                    'creation' => $this->creerLigne($ligne, $import, $actor, $compteurs),
                    'mise_a_jour' => $this->mettreAJourLigne($ligne, $import, $actor, $compteurs),
                    default => $compteurs['inchanges']++,
                };
            }
            unset($ligne);
        });

        return [
            'succes' => true,
            'rapport' => $rapportFinal,
            'compteurs' => $compteurs,
        ];
    }

    private function creerLigne(array &$ligne, ImportProduits $import, User $actor, array &$compteurs): void
    {
        $produit = $this->produitService->creer([
            ...$ligne['data'],
            'organization_id' => $import->organization_id,
        ]);

        $varianteDefaut = $produit->variantes->firstWhere('is_default', true) ?? $produit->variantes->first();
        $ligne['sku'] = $varianteDefaut?->sku;
        $ligne['produit_id'] = $produit->id;
        $compteurs['crees']++;

        $this->auditService->record(
            $produit,
            AuditEvent::CREATED,
            $actor,
            null,
            $this->snapshot($produit, $varianteDefaut),
            ['import_produits_id' => $import->id, 'ligne' => $ligne['numero_ligne']],
        );
    }

    private function mettreAJourLigne(array &$ligne, ImportProduits $import, User $actor, array &$compteurs): void
    {
        // Verrouille le produit ET sa variante par défaut avant de ré-appliquer la mise à jour —
        // protège contre un import concurrent ou une édition manuelle simultanée visant le même
        // produit (cf. plan : "protection contre deux imports concurrents visant le même SKU").
        $produit = Produit::whereKey($ligne['produit_id'])->lockForUpdate()->firstOrFail();
        $varianteAvant = ProduitVariante::where('produit_id', $produit->id)->where('is_default', true)->lockForUpdate()->first();

        $avant = $this->snapshot($produit, $varianteAvant);

        $produit = $this->produitService->mettreAJourSimple($produit, $ligne['data']);

        $varianteApres = $produit->variantes->firstWhere('is_default', true) ?? $produit->variantes->first();
        $apres = $this->snapshot($produit, $varianteApres);

        $compteurs['mis_a_jour']++;

        if ($avant !== $apres) {
            $this->auditService->record(
                $produit,
                AuditEvent::UPDATED,
                $actor,
                $avant,
                $apres,
                ['import_produits_id' => $import->id, 'ligne' => $ligne['numero_ligne']],
            );
        }
    }

    private function snapshot(Produit $produit, ?ProduitVariante $variante): array
    {
        return array_filter([
            'nom' => $produit->nom,
            'statut' => $produit->statut?->value,
            'categorie_id' => $produit->categorie_id,
            'fournisseur_id' => $produit->fournisseur_id,
            'description' => $produit->description,
            'code_barres' => $variante?->code_barres,
            'prix_achat' => $variante?->prix_achat,
            'prix_usine' => $variante?->prix_usine,
            'prix_usine_tricycle' => $variante?->prix_usine_tricycle,
            'prix_vente' => $variante?->prix_vente,
            'cout' => $variante?->cout,
            'alerte_stock_active' => $produit->alerte_stock_active,
            'seuil_alerte_stock' => $produit->seuil_alerte_stock,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Compare l'aperçu déjà présenté à l'utilisateur ($ancien, capturé au store()) à la
     * classification fraîchement recalculée ($nouveau) : statut de ligne, diff affiché, et
     * produit ciblé. Toute divergence signifie qu'un produit visé a changé entre l'aperçu et la
     * confirmation (autre import concurrent, édition manuelle...) — la confirmation doit alors
     * être refusée plutôt que d'appliquer un diff que l'utilisateur n'a jamais revu.
     *
     * @param  array<int, array>  $ancien
     * @param  array<int, array>  $nouveau
     */
    private function apercuAChange(array $ancien, array $nouveau): bool
    {
        if (count($ancien) !== count($nouveau)) {
            return true;
        }

        foreach ($nouveau as $i => $ligneNouvelle) {
            $ligneAncienne = $ancien[$i] ?? null;
            if ($ligneAncienne === null) {
                return true;
            }
            if (($ligneAncienne['statut'] ?? null) !== $ligneNouvelle['statut']) {
                return true;
            }
            if (($ligneAncienne['produit_id'] ?? null) !== ($ligneNouvelle['produit_id'] ?? null)) {
                return true;
            }
            if (($ligneAncienne['changements'] ?? []) !== $ligneNouvelle['changements']) {
                return true;
            }
        }

        return false;
    }
}
