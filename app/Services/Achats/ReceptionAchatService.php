<?php

namespace App\Services\Achats;

use App\Enums\StatutCommandeAchat;
use App\Models\CommandeAchat;
use App\Models\CommandeAchatLigne;
use App\Models\ProduitVariante;
use App\Models\ReceptionAchat;
use App\Models\ReceptionAchatLigne;
use App\Models\User;
use App\Services\MouvementStockService;
use App\Services\ReferenceNumeroService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Réception d'un bon de commande fournisseur validé, en une ou plusieurs fois (ADR 0021).
 *
 * - Le stock entre dès l'enregistrement, sur l'AGENCE DE LA COMMANDE (jamais choisie à la
 *   réception), par la primitive unique MouvementStockService::appliquer().
 * - Chaque quantité est bornée par le reliquat de sa ligne, relu sous verrou.
 * - Le coût unitaire (prix de la commande) est figé sur la ligne de réception.
 * - Le `prix_achat` de la variante est mis à jour, sauf s'il atteindrait le prix de vente d'un
 *   produit soumis à la règle de marge (ProduitService) : la réception n'est alors jamais bloquée,
 *   un avertissement est renvoyé.
 */
class ReceptionAchatService
{
    public const PREFIXE_REFERENCE = 'RCA';

    public function __construct(private readonly ReferenceNumeroService $references) {}

    /**
     * @param  array{date_reception: string, note?: string|null, lignes: list<array{id: string, qte_recue: int}>}  $data
     * @return array{reception: ReceptionAchat, avertissements: list<string>}
     */
    public function receptionner(CommandeAchat $commande, User $user, array $data): array
    {
        return DB::transaction(function () use ($commande, $user, $data) {
            $commande = CommandeAchat::whereKey($commande->id)->lockForUpdate()->firstOrFail();

            if (! $commande->isReceptionnable()) {
                throw ValidationException::withMessages([
                    'reception' => 'Seule une commande validée et non entièrement réceptionnée peut être réceptionnée.',
                ]);
            }
            if ($commande->site_id === null) {
                throw ValidationException::withMessages(['reception' => "Cette commande n'a pas d'agence de réception."]);
            }
            // Une marchandise ne se reçoit pas avant d'avoir été achetée.
            $dateAchat = $commande->dateAchat()?->toDateString();
            if ($dateAchat !== null && Carbon::parse($data['date_reception'])->toDateString() < $dateAchat) {
                throw ValidationException::withMessages([
                    'date_reception' => "La date de réception ne peut pas précéder la date d'achat (".$commande->dateAchat()->format('d/m/Y').').',
                ]);
            }

            $lignes = CommandeAchatLigne::where('commande_achat_id', $commande->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $quantites = $this->quantitesDemandees($data['lignes'], $lignes);

            [$reference, $numero] = $this->references->generer($commande->organization_id, self::PREFIXE_REFERENCE);

            $reception = ReceptionAchat::create([
                'organization_id' => $commande->organization_id,
                'commande_achat_id' => $commande->id,
                'site_id' => $commande->site_id,
                'reference' => $reference,
                'numero' => $numero,
                'date_reception' => $data['date_reception'],
                'note' => $data['note'] ?? null,
                'created_by' => $user->id,
            ]);

            $avertissements = [];

            foreach ($quantites as $ligneId => $qte) {
                $ligne = $lignes->get($ligneId);

                $receptionLigne = $reception->lignes()->create([
                    'commande_achat_ligne_id' => $ligne->id,
                    'variante_id' => $ligne->variante_id,
                    'qte_recue' => $qte,
                    'cout_unitaire' => $ligne->prix_achat_snapshot,
                ]);

                $mouvement = MouvementStockService::appliquer(
                    varianteId: $ligne->variante_id,
                    siteId: $commande->site_id,
                    orgId: $commande->organization_id,
                    type: 'entree',
                    quantite: $qte,
                    sourceType: ReceptionAchatLigne::class,
                    sourceId: $receptionLigne->id,
                    userId: $user->id,
                    date: $data['date_reception'],
                );

                $receptionLigne->update(['mouvement_stock_id' => $mouvement->id]);
                $ligne->increment('qte_recue', $qte);

                $avertissement = $this->mettreAJourPrixAchat($ligne->variante_id, (float) $ligne->prix_achat_snapshot);
                if ($avertissement !== null) {
                    $avertissements[] = $avertissement;
                }
            }

            $complete = $lignes->every(fn (CommandeAchatLigne $l) => $l->reliquat() === 0);
            $commande->update([
                'statut' => $complete ? StatutCommandeAchat::RECEPTIONNEE : StatutCommandeAchat::PARTIELLEMENT_RECEPTIONNEE,
            ]);

            return ['reception' => $reception, 'avertissements' => $avertissements];
        });
    }

    /**
     * @param  list<array{id: string, qte_recue: int}>  $saisies
     * @return array<string, int> quantités > 0 par ligne de commande
     */
    private function quantitesDemandees(array $saisies, $lignes): array
    {
        $quantites = [];
        $erreurs = [];

        foreach ($saisies as $i => $saisie) {
            $ligne = $lignes->get($saisie['id']);
            $qte = (int) $saisie['qte_recue'];

            if ($ligne === null) {
                $erreurs["lignes.{$i}.id"] = "Cette ligne n'appartient pas à la commande.";

                continue;
            }
            if ($qte === 0) {
                continue;
            }
            if ($ligne->variante_id === null) {
                $erreurs["lignes.{$i}.qte_recue"] = "Le produit « {$ligne->libelle_snapshot} » n'existe plus : il ne peut pas être réceptionné.";

                continue;
            }
            if ($qte > $ligne->reliquat()) {
                $erreurs["lignes.{$i}.qte_recue"] = "« {$ligne->libelle_snapshot} » : {$qte} saisis, mais seulement {$ligne->reliquat()} restent à recevoir.";

                continue;
            }

            $quantites[$ligne->id] = ($quantites[$ligne->id] ?? 0) + $qte;
        }

        if ($erreurs === [] && $quantites === []) {
            $erreurs['lignes'] = 'Saisissez au moins une quantité reçue.';
        }
        if ($erreurs !== []) {
            throw ValidationException::withMessages($erreurs);
        }

        return $quantites;
    }

    private function mettreAJourPrixAchat(string $varianteId, float $cout): ?string
    {
        $variante = ProduitVariante::with('produit.produitType')->find($varianteId);
        if ($variante === null || (int) $variante->prix_achat === (int) round($cout)) {
            return null;
        }

        $type = $variante->produit?->produitType;
        $soumisALaMarge = $type !== null && $type->isVendable() && $type->champPrixReference() === 'prix_achat';
        if ($soumisALaMarge && $cout >= (float) $variante->prix_vente) {
            $nom = (string) $variante->produit?->nom;

            return "Prix d'achat de « {$nom} » non mis à jour : ".number_format($cout, 0, ',', ' ')
                .' GNF atteindrait le prix de vente ('.number_format((float) $variante->prix_vente, 0, ',', ' ').' GNF).';
        }

        $variante->update(['prix_achat' => (int) round($cout)]);

        return null;
    }
}
