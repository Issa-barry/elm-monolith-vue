<?php

namespace App\Services\Contacts;

use App\Enums\AuditEvent;
use App\Enums\CommissionRegleStatut;
use App\Enums\ContactCategorie;
use App\Enums\PrestataireType;
use App\Models\CommissionConsultantAffectation;
use App\Models\CommissionRegle;
use App\Models\ContactSousCategorie;
use App\Models\Prestataire;
use App\Models\User;
use App\Services\AuditLogService;
use App\Support\Contacts\SousCategoriesSysteme;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Qualifications d'un prestataire (ADR 0060, lot 6b, CONTACT-038 à CONTACT-041).
 *
 * - Plusieurs qualifications égales, sans principale ; au moins une active (Q1).
 * - Seule la qualification système Consultant ouvre l'éligibilité aux commissions consultant
 *   (Prestataire::qualifieConsultant()) ; une qualification libre ne déclenche rien.
 * - D15 : retirer Consultant est refusé tant qu'une affectation de commission active ou un barème
 *   actif désigne le prestataire, sous verrou du prestataire, super_admin compris. Désactiver un
 *   prestataire garde son comportement actuel (Q4).
 * - `type` reste une copie de compatibilité (Q5) : ancienne valeur gardée si elle figure encore
 *   parmi les qualifications, sinon la première des trois anciennes valeurs portée, sinon vide.
 * - Une qualification désactivée se garde mais ne s'ajoute pas. Journal : ids seulement.
 */
final class QualificationsPrestataire
{
    public const MESSAGE_D15 = 'Ce prestataire est désigné consultant par une affectation ou un barème de commission actif : clôturez ou réaffectez d\'abord depuis Paramètres → Commissions.';

    public function __construct(private readonly AuditLogService $journal) {}

    /**
     * Qualifications demandées par un formulaire : `qualification_ids`, sinon l'ancien champ `type`
     * (anciens écrans et clients), traduit vers la qualification du même code.
     *
     * @param  list<string>|null  $ids
     * @return list<string>|null null : rien de demandé
     */
    public function idsDemandes(string $organizationId, ?array $ids, ?string $typeHerite): ?array
    {
        if ($ids !== null) {
            return array_values(array_unique(array_map('strval', $ids)));
        }
        if ($typeHerite === null || $typeHerite === '') {
            return null;
        }

        $id = $this->qualificationDuCode($organizationId, $typeHerite)?->id;
        if ($id === null) {
            throw ValidationException::withMessages(['qualification_ids' => 'Qualification inconnue.']);
        }

        return [$id];
    }

    /**
     * Remplace les qualifications du prestataire. À appeler dans une transaction (le formulaire
     * l'ouvre) ; verrouille le prestataire pour le contrôle D15.
     *
     * @param  list<string>  $ids
     */
    public function synchroniser(User $acteur, Prestataire $prestataire, array $ids): void
    {
        if ($prestataire->organization_id === null || $prestataire->organization_id !== $acteur->organization_id) {
            throw new NotFoundHttpException;
        }

        DB::transaction(function () use ($acteur, $prestataire, $ids) {
            /** @var Prestataire $verrouille */
            $verrouille = Prestataire::withTrashed()->whereKey($prestataire->id)->lockForUpdate()->firstOrFail();

            $demandees = array_values(array_unique($ids));
            $valides = ContactSousCategorie::where('organization_id', $verrouille->organization_id)
                ->where('categorie', ContactCategorie::PRESTATAIRE->value)
                ->whereIn('id', $demandees)
                ->get();
            if ($valides->count() !== count($demandees)) {
                throw ValidationException::withMessages(['qualification_ids' => 'Une qualification choisie est introuvable.']);
            }

            $actuelles = $verrouille->sousCategories()->pluck('contact_sous_categories.id')->all();
            $ajoutees = array_values(array_diff($demandees, $actuelles));
            if ($valides->whereIn('id', $ajoutees)->contains(fn (ContactSousCategorie $s) => ! $s->is_active)) {
                throw ValidationException::withMessages(['qualification_ids' => 'Une qualification désactivée ne peut plus être ajoutée.']);
            }
            if (! $valides->contains(fn (ContactSousCategorie $s) => $s->is_active)) {
                throw ValidationException::withMessages(['qualification_ids' => 'Au moins une qualification active est obligatoire.']);
            }

            $etaitConsultant = $verrouille->qualifieConsultant();
            $resteConsultant = $valides->contains(fn (ContactSousCategorie $s) => $s->code === PrestataireType::CONSULTANT->value);
            if ($etaitConsultant && ! $resteConsultant && self::estDesigneConsultant($verrouille)) {
                throw ValidationException::withMessages(['qualification_ids' => self::MESSAGE_D15]);
            }

            $retirees = array_values(array_diff($actuelles, $demandees));
            $relation = $verrouille->sousCategories();
            if ($retirees !== []) {
                $relation->detach($retirees);
            }
            if ($ajoutees !== []) {
                $relation->attach(array_fill_keys($ajoutees, ['created_by' => $acteur->id]));
            }

            $ancienType = $verrouille->type?->value;
            $type = $this->typeCompatible($ancienType, $valides);
            if ($type !== $ancienType) {
                $verrouille->forceFill(['type' => $type])->save();
            }
            $prestataire->setRawAttributes($verrouille->getAttributes(), true);
            $prestataire->unsetRelation('sousCategories');

            if ($ajoutees !== [] || $retirees !== [] || $type !== $ancienType) {
                $this->journal->record($verrouille, AuditEvent::UPDATED, $acteur,
                    $type !== $ancienType ? ['type' => $ancienType] : null,
                    $type !== $ancienType ? ['type' => $type] : null,
                    ['origine' => 'qualifications', 'qualifications_ajoutees' => $ajoutees, 'qualifications_retirees' => $retirees]);
            }
        });
    }

    /**
     * D15 : une affectation consultant active ou un barème actif (en cours ou à venir) désigne-t-il
     * ce prestataire ? Les dates sont comparées en PHP : aucune borne de période calculée en SQL.
     */
    public static function estDesigneConsultant(Prestataire $prestataire): bool
    {
        $aujourdhui = now()->startOfDay();
        $enCours = fn ($ligne) => $ligne->effective_to === null || $ligne->effective_to->gte($aujourdhui);

        return CommissionConsultantAffectation::where('organization_id', $prestataire->organization_id)
            ->where('prestataire_id', $prestataire->id)
            ->where('statut', CommissionRegleStatut::ACTIVE->value)
            ->get(['id', 'effective_to'])
            ->contains($enCours)
            || CommissionRegle::where('organization_id', $prestataire->organization_id)
                ->where('consultant_id', $prestataire->id)
                ->where('statut', CommissionRegleStatut::ACTIVE->value)
                ->get(['id', 'effective_to'])
                ->contains($enCours);
    }

    /**
     * Options d'un formulaire : qualifications actives, plus celles déjà portées même désactivées.
     *
     * @param  list<string>  $portees
     * @return list<array{value: string, label: string, actif: bool}>
     */
    public static function options(string $organizationId, array $portees = []): array
    {
        return ReferentielCategories::options(
            ContactSousCategorie::where('organization_id', $organizationId)->where('categorie', ContactCategorie::PRESTATAIRE->value)->get(),
            $portees,
        );
    }

    /** @return list<string> ids portés (vide si aucune qualification enregistrée) */
    public static function portees(Prestataire $prestataire): array
    {
        return $prestataire->sousCategories()->pluck('contact_sous_categories.id')->all();
    }

    /** @param  Collection<int, ContactSousCategorie>  $qualifications */
    private function typeCompatible(?string $ancien, Collection $qualifications): ?string
    {
        $codes = $qualifications->pluck('code')->all();
        if ($ancien !== null && in_array($ancien, $codes, true)) {
            return $ancien;
        }

        foreach ($qualifications->sortBy([['ordre', 'asc'], ['libelle', 'asc']]) as $q) {
            if (PrestataireType::tryFrom($q->code) !== null) {
                return $q->code;
            }
        }

        return null;
    }

    private function qualificationDuCode(string $organizationId, string $code): ?ContactSousCategorie
    {
        $trouver = fn () => ContactSousCategorie::where('organization_id', $organizationId)
            ->where('categorie', ContactCategorie::PRESTATAIRE->value)
            ->where('code', $code)
            ->first();

        // Organisation antérieure au lot 1 sans référentiel : il est créé (rejouable, sans doublon).
        return $trouver() ?? (SousCategoriesSysteme::initialiserPour($organizationId) >= 0 ? $trouver() : null);
    }
}
