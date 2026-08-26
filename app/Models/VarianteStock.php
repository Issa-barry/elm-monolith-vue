<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;

class VarianteStock extends Model
{
    use HasUlids;

    protected $table = 'variante_stocks';

    protected $fillable = [
        'organization_id',
        'produit_variante_id',
        'site_id',
        'qte_stock',
        'qte_reservee',
    ];

    protected $casts = [
        'qte_stock' => 'integer',
        'qte_reservee' => 'integer',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProduitVariante::class, 'produit_variante_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    // ── Concurrence ───────────────────────────────────────────────────────────

    /**
     * Récupère (verrouillée) la ligne variante × site, ou la crée si elle n'existe pas encore —
     * primitive UNIQUE utilisée par MouvementStockService::appliquer() et
     * StockReservationService::reserver(), pour ne jamais dupliquer ce correctif de concurrence
     * (24/25/08/2026).
     *
     * `lockForUpdate()` sur un SELECT qui ne trouve AUCUNE ligne ne verrouille rien (pas de ligne
     * = pas de verrou) : deux transactions concurrentes peuvent toutes deux constater l'absence
     * de la ligne et tenter chacune un INSERT sur (produit_variante_id, site_id) — la seconde
     * viole alors la contrainte unique et remonterait normalement une QueryException SQL brute
     * jusqu'à l'appelant métier. Ce correctif rattrape ce cas précis : si la création échoue pour
     * violation de contrainte d'intégrité (SQLSTATE 23000), on considère qu'une transaction
     * concurrente a gagné la course, et on relit la ligne sous verrou — qui la voit forcément
     * déjà committée à ce stade (une INSERT qui échoue par clé dupliquée ne peut se produire que
     * si l'autre transaction a déjà validé, cf. protocole verrou d'unicité InnoDB/SQLite).
     *
     * $connection : jamais renseigné en production (toujours la connexion par défaut de
     * l'application) — uniquement utilisé par le test de concurrence réelle
     * (VarianteStockConcurrenceTest), qui a besoin d'orchestrer deux connexions MySQL
     * explicitement distinctes pour reproduire fidèlement la course.
     */
    public static function lockOuCreer(string $varianteId, string $siteId, string $orgId, ?string $connection = null): self
    {
        $query = $connection ? static::on($connection) : static::query();

        $stock = $query->where('produit_variante_id', $varianteId)
            ->where('site_id', $siteId)
            ->lockForUpdate()
            ->first();

        if ($stock) {
            return $stock;
        }

        try {
            $stock = new static([
                'organization_id' => $orgId,
                'produit_variante_id' => $varianteId,
                'site_id' => $siteId,
                'qte_stock' => 0,
                'qte_reservee' => 0,
            ]);
            if ($connection) {
                $stock->setConnection($connection);
            }
            $stock->save();

            return $stock;
        } catch (QueryException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            $query = $connection ? static::on($connection) : static::query();

            return $query->where('produit_variante_id', $varianteId)
                ->where('site_id', $siteId)
                ->lockForUpdate()
                ->firstOrFail();
        }
    }
}
