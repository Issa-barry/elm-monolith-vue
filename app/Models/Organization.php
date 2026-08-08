<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = ['name', 'slug', 'code', 'siret', 'logo_path', 'is_active'];

    protected $appends = ['logo_url'];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Organization $org) {
            if (empty($org->code)) {
                $org->code = self::generateCode($org->name);
            }
        });
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? '/storage/'.$this->logo_path : null;
    }

    /**
     * Génère un code court ("trinôme") identifiant l'organisation avant
     * connexion (utilisé dans l'URL /login/{code} pour afficher le bon
     * logo/nom). Basé sur les initiales du nom (ex: "Eau la maman" → ELM,
     * "Fello Demo" → FDL), complété si besoin par les lettres suivantes du
     * premier mot, et rendu unique par un suffixe numérique en cas de
     * collision. Reste modifiable ensuite par un admin (voir OrganisationController).
     */
    public static function generateCode(string $name): string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];
        $letters = '';

        foreach ($words as $word) {
            $word = preg_replace('/[^A-Za-z]/', '', $word) ?? '';
            if ($word !== '') {
                $letters .= mb_strtoupper(mb_substr($word, 0, 1));
            }
            if (mb_strlen($letters) >= 3) {
                break;
            }
        }

        if (mb_strlen($letters) < 3) {
            $base = preg_replace('/[^A-Za-z]/', '', $words[0] ?? '') ?? '';
            for ($i = mb_strlen($letters); mb_strlen($letters) < 3 && $i < mb_strlen($base); $i++) {
                $letters .= mb_strtoupper(mb_substr($base, $i, 1));
            }
        }

        $letters = mb_substr(str_pad($letters, 3, 'X'), 0, 3);

        $code = $letters;
        $suffix = 1;
        while (self::where('code', $code)->exists()) {
            $code = $letters.$suffix;
            $suffix++;
        }

        return $code;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function prestataires(): HasMany
    {
        return $this->hasMany(Prestataire::class);
    }

    public function livreurs(): HasMany
    {
        return $this->hasMany(Livreur::class);
    }

    public function proprietaires(): HasMany
    {
        return $this->hasMany(Proprietaire::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }
}
