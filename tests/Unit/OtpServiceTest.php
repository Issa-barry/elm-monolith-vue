<?php

namespace Tests\Unit;

use App\Enums\OtpPurpose;
use App\Services\OtpService;
use Tests\TestCase;

/**
 * Couvre le mécanisme OTP générique (verrouillage, cooldown, contexte) —
 * indépendant du purpose choisi ici (PHONE_VERIFICATION), qui n'est qu'un
 * paramètre obligatoire parmi d'autres depuis le chantier OTP agnostique du
 * canal (cf. rapport du 27/08/2026). Le comportement testé est identique
 * quel que soit le purpose réellement utilisé.
 */
class OtpServiceTest extends TestCase
{
    private OtpService $otp;

    private const PURPOSE = OtpPurpose::PHONE_VERIFICATION;

    protected function setUp(): void
    {
        parent::setUp();
        $this->otp = app(OtpService::class);
    }

    // ── Vérification ──────────────────────────────────────────────────────────

    public function test_verify_reussit_avec_le_bon_code(): void
    {
        $this->otp->generate('+224620001001', self::PURPOSE);

        $this->assertTrue($this->otp->verify('+224620001001', '123456', self::PURPOSE));
    }

    public function test_verify_echoue_avec_un_mauvais_code(): void
    {
        $this->otp->generate('+224620001002', self::PURPOSE);

        $this->assertFalse($this->otp->verify('+224620001002', '000000', self::PURPOSE));
    }

    public function test_verify_echoue_sans_code_genere(): void
    {
        $this->assertFalse($this->otp->verify('+224620001003', '123456', self::PURPOSE));
    }

    // ── Usage unique : le code est supprimé après succès ──────────────────────

    public function test_le_code_ne_peut_pas_etre_rejoue_apres_un_succes(): void
    {
        $phone = '+224620001100';
        $this->otp->generate($phone, self::PURPOSE);

        $this->assertTrue($this->otp->verify($phone, '123456', self::PURPOSE));
        $this->assertFalse($this->otp->verify($phone, '123456', self::PURPOSE));
    }

    // ── Verrouillage après trop de tentatives ─────────────────────────────────

    public function test_le_code_est_verrouille_apres_5_echecs(): void
    {
        $phone = '+224620001004';
        $this->otp->generate($phone, self::PURPOSE);

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($this->otp->verify($phone, '000000', self::PURPOSE));
        }

        $this->assertTrue($this->otp->tooManyAttempts($phone, self::PURPOSE));

        // Même le bon code est désormais refusé : il faut en redemander un nouveau.
        $this->assertFalse($this->otp->verify($phone, '123456', self::PURPOSE));
    }

    public function test_un_echec_ne_declenche_pas_le_verrouillage(): void
    {
        $phone = '+224620001005';
        $this->otp->generate($phone, self::PURPOSE);

        $this->otp->verify($phone, '000000', self::PURPOSE);

        $this->assertFalse($this->otp->tooManyAttempts($phone, self::PURPOSE));
        $this->assertTrue($this->otp->verify($phone, '123456', self::PURPOSE));
    }

    public function test_un_succes_reinitialise_le_compteur_de_tentatives(): void
    {
        $phone = '+224620001006';
        $this->otp->generate($phone, self::PURPOSE);

        $this->otp->verify($phone, '000000', self::PURPOSE);
        $this->otp->verify($phone, '000000', self::PURPOSE);
        $this->assertTrue($this->otp->verify($phone, '123456', self::PURPOSE));

        $this->assertFalse($this->otp->tooManyAttempts($phone, self::PURPOSE));
    }

    public function test_generer_un_nouveau_code_reinitialise_le_verrouillage(): void
    {
        $phone = '+224620001007';
        $this->otp->generate($phone, self::PURPOSE);

        for ($i = 0; $i < 5; $i++) {
            $this->otp->verify($phone, '000000', self::PURPOSE);
        }
        $this->assertTrue($this->otp->tooManyAttempts($phone, self::PURPOSE));

        $this->otp->generate($phone, self::PURPOSE);

        $this->assertFalse($this->otp->tooManyAttempts($phone, self::PURPOSE));
        $this->assertTrue($this->otp->verify($phone, '123456', self::PURPOSE));
    }

    // ── Anti-spam de renvoi (cooldown + plafonds horaire/journalier) ──────────

    public function test_can_send_est_faux_juste_apres_generation(): void
    {
        $phone = '+224620001008';
        $this->otp->generate($phone, self::PURPOSE);

        $this->assertFalse($this->otp->canSend($phone));
    }

    public function test_can_send_est_vrai_avant_toute_generation(): void
    {
        $this->assertTrue($this->otp->canSend('+224620001009'));
    }

    public function test_can_send_devient_faux_au_dela_de_5_envois_par_heure(): void
    {
        $phone = '+224620001101';

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($this->otp->canSend($phone), "envoi {$i} devrait être autorisé");
            $this->otp->generate($phone, self::PURPOSE);
            // Passe le cooldown de 30s sans sortir de la fenêtre horaire, pour isoler
            // le plafond de 5 envois/heure dans ce test.
            $this->travel(31)->seconds();
        }

        $this->assertFalse($this->otp->canSend($phone));
    }

    /**
     * Les compteurs anti-abus sont partagés entre purposes (cf. docblock de
     * OtpService) : demander tour à tour un code login/phone_verification pour
     * le même numéro consomme le MÊME quota horaire, pas deux quotas séparés.
     */
    public function test_le_plafond_horaire_est_partage_entre_purposes(): void
    {
        $phone = '+224620001102';

        for ($i = 0; $i < 5; $i++) {
            $this->otp->generate($phone, OtpPurpose::LOGIN);
            $this->travel(31)->seconds();
        }

        $this->assertFalse($this->otp->canSend($phone));
    }

    // ── clear() ───────────────────────────────────────────────────────────────

    public function test_clear_supprime_le_code_et_les_tentatives(): void
    {
        $phone = '+224620001010';
        $this->otp->generate($phone, self::PURPOSE);
        $this->otp->verify($phone, '000000', self::PURPOSE);

        $this->otp->clear($phone, self::PURPOSE);

        $this->assertFalse($this->otp->verify($phone, '123456', self::PURPOSE));
        $this->assertFalse($this->otp->tooManyAttempts($phone, self::PURPOSE));
    }

    // ── Contexte : lie un code à une entité précise (ex: une invitation) ──────

    public function test_deux_contextes_differents_ont_des_codes_independants(): void
    {
        $phone = '+224620001011';

        $this->otp->generate($phone, self::PURPOSE, 'invitation-A');
        $this->otp->generate($phone, self::PURPOSE, 'invitation-B');

        // Verrouiller le contexte A n'affecte pas le contexte B.
        for ($i = 0; $i < 5; $i++) {
            $this->otp->verify($phone, '000000', self::PURPOSE, 'invitation-A');
        }
        $this->assertTrue($this->otp->tooManyAttempts($phone, self::PURPOSE, 'invitation-A'));
        $this->assertFalse($this->otp->tooManyAttempts($phone, self::PURPOSE, 'invitation-B'));
        $this->assertTrue($this->otp->verify($phone, '123456', self::PURPOSE, 'invitation-B'));
    }

    public function test_un_code_sans_contexte_nest_pas_valide_avec_un_contexte(): void
    {
        $phone = '+224620001012';
        $this->otp->generate($phone, self::PURPOSE);

        $this->assertFalse($this->otp->verify($phone, '123456', self::PURPOSE, 'invitation-X'));
    }

    // ── Purpose : deux challenges indépendants pour le même identifiant ───────

    /**
     * Un code généré pour `login` n'est jamais valide pour `phone_verification`
     * sur le MÊME numéro, même généré au même instant (cf. rapport du
     * 27/08/2026, séparation purpose/canal/vérification d'identité).
     */
    public function test_un_code_genere_pour_un_purpose_nest_pas_valide_pour_un_autre(): void
    {
        $phone = '+224620001013';

        $this->otp->generate($phone, OtpPurpose::LOGIN);

        $this->assertFalse($this->otp->verify($phone, '123456', OtpPurpose::PHONE_VERIFICATION));
        $this->assertTrue($this->otp->verify($phone, '123456', OtpPurpose::LOGIN));
    }

    public function test_verrouiller_un_purpose_naffecte_pas_un_autre_purpose(): void
    {
        $phone = '+224620001014';
        $this->otp->generate($phone, OtpPurpose::LOGIN);
        $this->otp->generate($phone, OtpPurpose::PHONE_VERIFICATION);

        for ($i = 0; $i < 5; $i++) {
            $this->otp->verify($phone, '000000', OtpPurpose::LOGIN);
        }

        $this->assertTrue($this->otp->tooManyAttempts($phone, OtpPurpose::LOGIN));
        $this->assertFalse($this->otp->tooManyAttempts($phone, OtpPurpose::PHONE_VERIFICATION));
        $this->assertTrue($this->otp->verify($phone, '123456', OtpPurpose::PHONE_VERIFICATION));
    }
}
