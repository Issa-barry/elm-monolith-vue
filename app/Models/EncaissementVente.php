<?php

namespace App\Models;

use App\Enums\EvenementComptable;
use App\Enums\ModePaiement;
use App\Enums\OperateurMobileMoney;
use App\Services\Comptabilite\EcritureComptableService;
use App\Services\Comptabilite\VenteComptabilisationService;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class EncaissementVente extends Model
{
    use HasFactory, HasUlids;

    public const MESSAGE_REFERENCE_MOBILE_MONEY_UTILISEE = 'Cette référence Mobile Money a déjà été utilisée.';

    protected $table = 'encaissements_ventes';

    protected $fillable = [
        'facture_vente_id',
        'site_encaissement_id',
        'montant',
        'date_encaissement',
        'mode_paiement',
        'operateur_mobile_money',
        'compte_tresorerie_id',
        'reference_paiement',
        'note',
        'est_acompte',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'date_encaissement' => 'date:Y-m-d',
            'mode_paiement' => ModePaiement::class,
            'operateur_mobile_money' => OperateurMobileMoney::class,
            'est_acompte' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Une référence Mobile Money ne sert qu'une fois par organisation, tous opérateurs confondus
        // (règle du 01/10/2026, ADR 0014) : `cle_reference_mobile_money` porte l'index unique, dernière
        // protection contre deux saisies simultanées. Calculée ici pour que tout appelant (contrôleur,
        // seeder, import...) y soit soumis, et seulement quand ses entrées changent : un doublon
        // historique, laissé sans clé par la migration, reste modifiable sans faire échouer l'index.
        static::saving(fn (EncaissementVente $e) => $e->appliquerCleReferenceMobileMoney());

        static::creating(function (EncaissementVente $e) {
            if (Auth::check()) {
                $e->created_by = Auth::id();
            }

            // Sans agence d'encaissement explicite, l'argent est reçu par l'agence de la facture —
            // le comportement de tous les encaissements antérieurs à l'ADR 0012.
            if (! $e->site_encaissement_id) {
                $e->site_encaissement_id = $e->facture?->site_id;
            }
        });

        // Un encaissement déjà engagé dans un règlement inter-agences (même en brouillon) ne se
        // supprime pas : ses fonds sont reversés, ou en passe de l'être, à l'agence de la commande
        // — le contrepasser ferait sortir de la trésorerie de l'agence qui a encaissé un argent
        // qu'elle ne détient plus (ADR 0012). Garde au niveau du modèle : aucun appelant ne peut
        // l'oublier. Verrou sur la ligne : une création de règlement concurrente se sérialise.
        static::deleting(function (EncaissementVente $e) {
            static::whereKey($e->id)->lockForUpdate()->first();

            if ($ligne = $e->ligneReglementActive()) {
                throw ValidationException::withMessages([
                    'encaissement' => $e->messageReglementActif($ligne),
                ]);
            }
        });

        static::created(function (EncaissementVente $e) {
            $facture = $e->facture;
            if ($facture) {
                $facture->recalculStatut();
                $facture->commande->cloturerSiComplete();
            }

            // Comptabilité générale : un encaissement fait entrer de la trésorerie
            // réelle — bloquant depuis la revue Codex du 2026-08-22 (même raison que
            // PaiementFichePaiement/PaiePaiement/Depense). Ventes\StoreEncaissementVenteController
            // englobe déjà cette création dans une transaction couvrant aussi
            // la transition de statut de la facture et le déclenchement cashback — un
            // échec ici annule l'ensemble, cohérent avec le commentaire déjà présent
            // sur cette transaction ("doivent réussir ou échouer ensemble").
            app(VenteComptabilisationService::class)->comptabiliserEncaissementVente($e);
        });

        static::deleted(function (EncaissementVente $e) {
            $facture = $e->facture;
            if ($facture) {
                $facture->recalculStatut();
                $facture->commande->cloturerSiComplete();
            }

            // Jamais de suppression destructive d'écriture validée (règle #29) : on
            // contrepasse les pièces d'encaissement si elles existent, on ne les supprime
            // jamais. Ventes\DestroyEncaissementVenteController englobe déjà cette
            // suppression dans une transaction.
            if ($facture) {
                static::contrepasserPieces($e, $facture->organization_id);
            }
        });
    }

    /**
     * Un encaissement pour le compte d'une autre agence a deux pièces (site d'encaissement et site
     * de la commande, ADR 0012) : les deux sont contrepassées, la dette inter-agences disparaît.
     */
    private static function contrepasserPieces(self $e, string $organizationId): void
    {
        $ecritures = app(EcritureComptableService::class);

        foreach ([EvenementComptable::ENCAISSEMENT_VENTE_RECU, EvenementComptable::ENCAISSEMENT_VENTE_POUR_COMPTE] as $evenement) {
            $piece = $ecritures->pieceExistantePour($organizationId, $e, $evenement);
            if ($piece && $piece->isValidee()) {
                $ecritures->contrepasser($piece, 'Encaissement supprimé');
            }
        }
    }

    // ── Référence Mobile Money ───────────────────────────────────────────────

    /** Espaces de bord et casse ignorés : « om123 », « OM123 » et «  OM123  » sont la même référence. */
    public static function normaliserReference(?string $reference): ?string
    {
        $reference = mb_strtoupper(trim((string) $reference));

        return $reference === '' ? null : $reference;
    }

    public static function cleReferenceMobileMoney(?string $organizationId, ?string $reference): ?string
    {
        $reference = static::normaliserReference($reference);

        return $organizationId === null || $reference === null ? null : $organizationId.'|'.$reference;
    }

    /**
     * Référence (VTE-…) de la facture qui utilise déjà cette référence Mobile Money ; chaîne vide si
     * la facture n'a pas de référence, null si la référence Mobile Money est libre.
     */
    public static function factureUtilisantReferenceMobileMoney(string $organizationId, ?string $reference): ?string
    {
        $cle = static::cleReferenceMobileMoney($organizationId, $reference);
        if ($cle === null) {
            return null;
        }

        $utilisation = static::query()
            ->join('factures_ventes as f', 'f.id', '=', 'encaissements_ventes.facture_vente_id')
            ->where('encaissements_ventes.cle_reference_mobile_money', $cle)
            ->first(['f.reference']);

        return $utilisation ? (string) $utilisation->reference : null;
    }

    private function appliquerCleReferenceMobileMoney(): void
    {
        if ($this->exists && ! $this->isDirty(['mode_paiement', 'reference_paiement', 'facture_vente_id'])) {
            return;
        }

        if ($this->mode_paiement !== ModePaiement::MOBILE_MONEY) {
            $this->cle_reference_mobile_money = null;

            return;
        }

        $this->reference_paiement = static::normaliserReference($this->reference_paiement);
        $this->cle_reference_mobile_money = static::cleReferenceMobileMoney($this->facture?->organization_id, $this->reference_paiement);
    }

    /** Le doublon refusé par la base vient-il de l'index des références Mobile Money (et pas d'une autre contrainte) ? */
    public static function estDoublonReferenceMobileMoney(UniqueConstraintViolationException $e): bool
    {
        return str_contains($e->getMessage(), 'cle_reference_mobile_money');
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function facture(): BelongsTo
    {
        return $this->belongsTo(FactureVente::class, 'facture_vente_id');
    }

    /** Agence qui a réellement reçu l'argent (ADR 0012) — celle de la facture sauf encaissement dans une autre agence. */
    public function siteEncaissement(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_encaissement_id');
    }

    /** Lignes de règlement inter-agences (actives et historiques). */
    public function lignesReglement(): HasMany
    {
        return $this->hasMany(MouvementFondsEncaissement::class, 'encaissement_vente_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Support choisi à l'encaissement (Mobile Money, virement, chèque) — null pour les espèces et l'historique. */
    public function compteTresorerie(): BelongsTo
    {
        return $this->belongsTo(CompteTresorerie::class, 'compte_tresorerie_id');
    }

    // ── Inter-agences (ADR 0012) ──────────────────────────────────────────────

    /**
     * L'argent a été reçu par une autre agence que celle de la commande : l'agence qui a encaissé
     * le doit à l'agence de la commande jusqu'au règlement inter-agences.
     */
    public function estPourAutreAgence(): bool
    {
        $siteFacture = $this->facture?->site_id;

        return $siteFacture !== null
            && $this->site_encaissement_id !== null
            && $this->site_encaissement_id !== $siteFacture;
    }

    public function ligneReglementActive(): ?MouvementFondsEncaissement
    {
        return MouvementFondsEncaissement::where('encaissement_actif_id', $this->id)->with('mouvement')->first();
    }

    public function messageReglementActif(MouvementFondsEncaissement $ligne): string
    {
        $mouvement = $ligne->mouvement;
        $reference = $mouvement?->reference ?? '—';

        if ($mouvement?->isBrouillon()) {
            return "Cet encaissement fait partie du règlement inter-agences {$reference}, en préparation : annulez d'abord ce règlement pour pouvoir le supprimer.";
        }

        return "Cet encaissement a été reversé à l'agence de la commande par le règlement inter-agences {$reference} : il ne peut plus être supprimé.";
    }
}
