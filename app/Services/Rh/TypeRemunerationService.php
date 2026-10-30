<?php

namespace App\Services\Rh;

use App\Enums\AuditEvent;
use App\Enums\ModeCalculRemuneration;
use App\Enums\StatutTypeRemuneration;
use App\Models\TypeRemuneration;
use App\Models\User;
use App\Services\AuditLogService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Seul point d'écriture du référentiel des primes et avantages (docs/primes-avantages.md, ADR 0038).
 *
 * Le droit de validation (`rh-types-remuneration.valider`) est contrôlé ICI, en plus des
 * contrôleurs : valider, fermer, désactiver, réactiver, ainsi que modifier ou supprimer un type
 * déjà validé. L'organisation est toujours contrôlée explicitement (le Gate::before du super
 * administrateur ne la vérifie pas). Chaque changement d'état se fait sous verrou du type.
 */
class TypeRemunerationService
{
    public const PERMISSION_VALIDATION = 'rh-types-remuneration.valider';

    private const MODULE_AUDIT = 'rh_types_remuneration';

    private const LONGUEUR_CODE = 30;

    /** Caractéristiques de calcul, figées dès que le type a servi (il faut alors créer un nouveau type). */
    private const CHAMPS_FIGES_APRES_USAGE = ['code', 'categorie', 'mode_calcul', 'periodicite', 'nature_montant'];

    private const CHAMPS_JOURNAL = [
        'code', 'libelle', 'description', 'categorie', 'mode_calcul', 'periodicite', 'nature_montant',
        'proratisable', 'cumul_autorise', 'montant_defaut', 'taux_defaut',
        'date_debut_application', 'date_fin_application', 'statut',
    ];

    public function __construct(private readonly AuditLogService $audit) {}

    /**
     * Crée un type « à valider », ou directement actif si l'auteur le demande et en a le droit.
     *
     * @param  array<string, mixed>  $data  données validées par TypeRemunerationRequest
     */
    public function creer(array $data, User $acteur, bool $valider = false): TypeRemuneration
    {
        $organizationId = $this->organisationDe($acteur);
        if ($valider) {
            $this->exigerDroitValidation($acteur);
        }

        return $this->enregistrer(function () use ($data, $acteur, $organizationId, $valider) {
            $attributs = $this->attributsNormalises($data, $organizationId);

            $type = TypeRemuneration::create([
                ...$attributs,
                'code' => $attributs['code'] ?? $this->codePropose($attributs['libelle'], $organizationId),
                'organization_id' => $organizationId,
                'statut' => StatutTypeRemuneration::A_VALIDER->value,
                'cree_par' => $acteur->id,
                'modifie_par' => $acteur->id,
            ]);

            $this->journaliser($type, AuditEvent::CREATED, $acteur, null, $this->instantane($type), "Type « {$type->libelle} » créé");

            if ($valider) {
                $this->appliquerValidation($type, $acteur);
            }

            return $type;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function modifier(TypeRemuneration $type, array $data, User $acteur, bool $valider = false): TypeRemuneration
    {
        $this->assertMemeOrganisation($type, $acteur);
        if ($valider) {
            $this->exigerDroitValidation($acteur);
        }

        return $this->enregistrer(function () use ($type, $data, $acteur, $valider) {
            $type = $this->verrouiller($type);

            // Décision du 28/10/2026 : un type validé ne se modifie qu'avec le droit de validation ;
            // sans lui, l'utilisateur ne modifie que ses propositions « à valider ».
            if ($type->statut->estValide()) {
                $this->exigerDroitValidation($acteur, 'Ce type a déjà été validé : seule une personne habilitée à valider les primes peut le modifier.');
            }

            $attributs = $this->attributsNormalises($data, $type->organization_id, $type->id);
            if (! array_key_exists('code', $attributs) || $attributs['code'] === null) {
                $attributs['code'] = $type->code;
            }
            $this->assertCaracteristiquesNonFigees($type, $attributs);

            $avant = $this->instantane($type);
            $type->update([...$attributs, 'modifie_par' => $acteur->id]);
            [$ancien, $nouveau] = $this->audit->diffFields($avant, $this->instantane($type), self::CHAMPS_JOURNAL);

            if ($nouveau !== null) {
                $this->journaliser($type, AuditEvent::UPDATED, $acteur, $ancien, $nouveau, "Type « {$type->libelle} » modifié");
            }

            if ($valider && $type->statut === StatutTypeRemuneration::A_VALIDER) {
                $this->appliquerValidation($type, $acteur);
            }

            return $type;
        });
    }

    public function valider(TypeRemuneration $type, User $acteur): TypeRemuneration
    {
        return $this->changerStatut($type, $acteur, [StatutTypeRemuneration::A_VALIDER], StatutTypeRemuneration::ACTIF,
            'Seul un type « à valider » peut être validé.');
    }

    /** Plus de nouvelle attribution ; les bénéficiaires actuels sont maintenus. */
    public function fermer(TypeRemuneration $type, User $acteur): TypeRemuneration
    {
        return $this->changerStatut($type, $acteur, [StatutTypeRemuneration::ACTIF], StatutTypeRemuneration::FERME,
            'Seul un type actif peut être fermé aux nouvelles attributions.');
    }

    /**
     * Plus aucun montant de ce type n'est dû à partir de la date d'effet (ADR 0039). Date
     * obligatoire dès qu'un salarié en bénéficie encore : ses affectations s'arrêtent la veille
     * (AffectationRemunerationService::arreterPourDesactivation()). Sans bénéficiaire, la
     * désactivation prend effet le jour même.
     */
    public function desactiver(TypeRemuneration $type, User $acteur, ?string $dateEffet = null): TypeRemuneration
    {
        $this->assertMemeOrganisation($type, $acteur);
        $this->exigerDroitValidation($acteur);
        $affectations = app(AffectationRemunerationService::class);

        return DB::transaction(function () use ($type, $acteur, $dateEffet, $affectations) {
            $type = $this->verrouiller($type);
            if (! in_array($type->statut, [StatutTypeRemuneration::ACTIF, StatutTypeRemuneration::FERME], true)) {
                throw ValidationException::withMessages(['statut' => 'Seul un type actif ou fermé peut être désactivé.']);
            }

            $effet = filled($dateEffet) ? Carbon::parse($dateEffet)->startOfDay() : today();
            if (! filled($dateEffet) && ($nb = $affectations->nbBeneficiairesOuverts($type, $effet)) > 0) {
                throw ValidationException::withMessages([
                    'date_effet' => "{$nb} affectation(s) de ce type sont encore en cours ou à venir : indiquez la date à partir de laquelle plus rien n'est versé.",
                ]);
            }

            $bilan = $affectations->arreterPourDesactivation($type, $effet, $acteur);

            $ancien = $type->statut;
            $type->update([
                'statut' => StatutTypeRemuneration::INACTIF->value,
                'desactive_au' => $effet->toDateString(),
                'modifie_par' => $acteur->id,
            ]);

            $detail = $bilan['terminees'] + $bilan['annulees'] > 0
                ? " ({$bilan['terminees']} affectation(s) arrêtée(s), {$bilan['annulees']} annulée(s))"
                : '';
            $this->journaliser($type, AuditEvent::STATUS_CHANGED, $acteur,
                ['statut' => $ancien->value], ['statut' => StatutTypeRemuneration::INACTIF->value, 'desactive_au' => $effet->toDateString()],
                "Type « {$type->libelle} » désactivé au {$effet->format('d/m/Y')}{$detail}");

            return $type;
        });
    }

    /**
     * Même code, même identifiant : le type redevient attribuable. Les affectations arrêtées par
     * une désactivation ne reprennent pas d'elles-mêmes : il faut les attribuer à nouveau.
     */
    public function reactiver(TypeRemuneration $type, User $acteur): TypeRemuneration
    {
        return DB::transaction(function () use ($type, $acteur) {
            $type = $this->changerStatut($type, $acteur, [StatutTypeRemuneration::FERME, StatutTypeRemuneration::INACTIF], StatutTypeRemuneration::ACTIF,
                'Seul un type fermé ou inactif peut être réactivé.');
            if ($type->desactive_au !== null) {
                $type->update(['desactive_au' => null]);
            }

            return $type;
        });
    }

    /**
     * Suppression d'un type qui n'a jamais servi (décision du 28/10/2026) ; sinon il se désactive.
     * Un type déjà validé exige en plus le droit de validation, comme sa modification.
     */
    public function supprimer(TypeRemuneration $type, User $acteur): void
    {
        $this->assertMemeOrganisation($type, $acteur);

        DB::transaction(function () use ($type, $acteur) {
            $type = $this->verrouiller($type);

            if ($type->statut->estValide()) {
                $this->exigerDroitValidation($acteur, 'Ce type a déjà été validé : seule une personne habilitée à valider les primes peut le supprimer.');
            }
            if ($this->estUtilise($type)) {
                throw ValidationException::withMessages([
                    'type' => 'Ce type a déjà été attribué : désactivez-le plutôt que de le supprimer.',
                ]);
            }

            $this->journaliser($type, AuditEvent::DELETED, $acteur, $this->instantane($type), null, "Type « {$type->libelle} » supprimé");
            $type->delete();
        });
    }

    /**
     * @param  list<StatutTypeRemuneration>  $depuis
     */
    private function changerStatut(TypeRemuneration $type, User $acteur, array $depuis, StatutTypeRemuneration $vers, string $refus): TypeRemuneration
    {
        $this->assertMemeOrganisation($type, $acteur);
        $this->exigerDroitValidation($acteur);

        return DB::transaction(function () use ($type, $acteur, $depuis, $vers, $refus) {
            $type = $this->verrouiller($type);

            if (! in_array($type->statut, $depuis, true)) {
                throw ValidationException::withMessages(['statut' => $refus]);
            }

            if ($vers === StatutTypeRemuneration::ACTIF && $type->statut === StatutTypeRemuneration::A_VALIDER) {
                return $this->appliquerValidation($type, $acteur);
            }

            $ancien = $type->statut;
            $type->update(['statut' => $vers->value, 'modifie_par' => $acteur->id]);

            $this->journaliser($type, AuditEvent::STATUS_CHANGED, $acteur,
                ['statut' => $ancien->value], ['statut' => $vers->value],
                "Type « {$type->libelle} » : {$ancien->label()} → {$vers->label()}");

            return $type;
        });
    }

    private function appliquerValidation(TypeRemuneration $type, User $acteur): TypeRemuneration
    {
        $type->update([
            'statut' => StatutTypeRemuneration::ACTIF->value,
            'valide_par' => $acteur->id,
            'valide_le' => now(),
            'modifie_par' => $acteur->id,
        ]);

        $this->journaliser($type, AuditEvent::VALIDATED, $acteur,
            ['statut' => StatutTypeRemuneration::A_VALIDER->value], ['statut' => StatutTypeRemuneration::ACTIF->value],
            "Type « {$type->libelle} » validé");

        return $type;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributsNormalises(array $data, string $organizationId, ?string $ignoreId = null): array
    {
        $libelle = trim((string) $data['libelle']);
        $this->assertLibelleLibre($libelle, $organizationId, $ignoreId);

        $code = null;
        if (filled($data['code'] ?? null)) {
            $code = $this->normaliserCode((string) $data['code']);
            if ($code === '') {
                throw ValidationException::withMessages(['code' => 'Le code doit contenir au moins une lettre ou un chiffre.']);
            }
            $this->assertCodeLibre($code, $organizationId, $ignoreId);
        }

        $pourcentage = $data['mode_calcul'] === ModeCalculRemuneration::POURCENTAGE_SALAIRE_BASE->value;

        return [
            'code' => $code,
            'libelle' => $libelle,
            'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
            'categorie' => $data['categorie'],
            'mode_calcul' => $data['mode_calcul'],
            'periodicite' => $data['periodicite'],
            'nature_montant' => $data['nature_montant'],
            'proratisable' => (bool) ($data['proratisable'] ?? false),
            'cumul_autorise' => (bool) ($data['cumul_autorise'] ?? false),
            // Une seule valeur par défaut, selon le mode de calcul : l'autre est toujours vide.
            'montant_defaut' => $pourcentage ? null : ($data['montant_defaut'] ?? null),
            'taux_defaut' => $pourcentage ? ($data['taux_defaut'] ?? null) : null,
            'date_debut_application' => $data['date_debut_application'] ?? null,
            'date_fin_application' => $data['date_fin_application'] ?? null,
        ];
    }

    /** @param  array<string, mixed>  $attributs */
    private function assertCaracteristiquesNonFigees(TypeRemuneration $type, array $attributs): void
    {
        if (! $this->estUtilise($type)) {
            return;
        }

        foreach (self::CHAMPS_FIGES_APRES_USAGE as $champ) {
            $actuel = $type->{$champ} instanceof \BackedEnum ? $type->{$champ}->value : $type->{$champ};
            if ((string) $actuel !== (string) $attributs[$champ]) {
                throw ValidationException::withMessages([
                    $champ => 'Ce type a déjà été attribué : ses caractéristiques de calcul ne changent plus. Créez un nouveau type.',
                ]);
            }
        }
    }

    private function assertLibelleLibre(string $libelle, string $organizationId, ?string $ignoreId): void
    {
        $pris = TypeRemuneration::where('organization_id', $organizationId)
            ->whereRaw('LOWER(libelle) = ?', [mb_strtolower($libelle)])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($pris) {
            throw ValidationException::withMessages(['libelle' => "Un type portant ce libellé existe déjà (« {$libelle} »)."]);
        }
    }

    private function assertCodeLibre(string $code, string $organizationId, ?string $ignoreId): void
    {
        if ($this->codePris($code, $organizationId, $ignoreId)) {
            throw ValidationException::withMessages(['code' => "Ce code est déjà utilisé par un autre type (« {$code} »)."]);
        }
    }

    private function codePris(string $code, string $organizationId, ?string $ignoreId = null): bool
    {
        return TypeRemuneration::where('organization_id', $organizationId)
            ->where('code', $code)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }

    /** « Prime de transport » → PRIME_DE_TRANSPORT ; suffixe _2, _3… si le code est pris. */
    private function codePropose(string $libelle, string $organizationId): string
    {
        $base = $this->normaliserCode($libelle) ?: 'TYPE';
        $code = $base;

        for ($n = 2; $this->codePris($code, $organizationId); $n++) {
            $suffixe = '_'.$n;
            $code = rtrim(substr($base, 0, self::LONGUEUR_CODE - strlen($suffixe)), '_').$suffixe;
        }

        return $code;
    }

    private function normaliserCode(string $valeur): string
    {
        $code = Str::upper(Str::ascii($valeur));
        $code = preg_replace('/[^A-Z0-9]+/', '_', $code) ?? '';

        return trim(substr(trim($code, '_'), 0, self::LONGUEUR_CODE), '_');
    }

    /**
     * Les index uniques (organisation + code, organisation + libellé) tranchent deux créations
     * simultanées : la seconde reçoit une erreur de validation, jamais une erreur 500.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function enregistrer(callable $operation): mixed
    {
        try {
            return DB::transaction($operation);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'libelle' => 'Un type portant ce libellé ou ce code vient d\'être enregistré. Rechargez la page.',
            ]);
        }
    }

    /** Point unique du contrôle d'usage (cf. TypeRemuneration::estUtilise()). */
    protected function estUtilise(TypeRemuneration $type): bool
    {
        return $type->estUtilise();
    }

    private function verrouiller(TypeRemuneration $type): TypeRemuneration
    {
        return TypeRemuneration::whereKey($type->id)->lockForUpdate()->firstOrFail();
    }

    private function organisationDe(User $acteur): string
    {
        if (! $acteur->organization_id) {
            throw new AuthorizationException;
        }

        return $acteur->organization_id;
    }

    private function assertMemeOrganisation(TypeRemuneration $type, User $acteur): void
    {
        if ($type->organization_id !== $this->organisationDe($acteur)) {
            throw new AuthorizationException;
        }
    }

    private function exigerDroitValidation(User $acteur, ?string $message = null): void
    {
        if (! $acteur->can(self::PERMISSION_VALIDATION)) {
            throw new AuthorizationException($message ?? 'Seule une personne habilitée à valider les primes peut effectuer cette action.');
        }
    }

    /** @return array<string, mixed> */
    private function instantane(TypeRemuneration $type): array
    {
        $valeurs = [];
        foreach (self::CHAMPS_JOURNAL as $champ) {
            $valeur = $type->{$champ};
            $valeurs[$champ] = match (true) {
                $valeur instanceof \BackedEnum => $valeur->value,
                $valeur instanceof \DateTimeInterface => $valeur->format('Y-m-d'),
                default => $valeur,
            };
        }

        return $valeurs;
    }

    /**
     * @param  array<string, mixed>|null  $avant
     * @param  array<string, mixed>|null  $apres
     */
    private function journaliser(TypeRemuneration $type, AuditEvent $evenement, User $acteur, ?array $avant, ?array $apres, string $description): void
    {
        $this->audit->record($type, $evenement, $acteur, $avant, $apres, [
            'module' => self::MODULE_AUDIT,
            'description' => $description,
            'code' => $type->code,
        ]);
    }
}
