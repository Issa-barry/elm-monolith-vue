<?php

namespace Tests\Unit;

use App\Services\OtpService;
use Tests\TestCase;

class OtpServiceTest extends TestCase
{
    private OtpService $otp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->otp = app(OtpService::class);
    }

    // ── Vérification ──────────────────────────────────────────────────────────

    public function test_verify_reussit_avec_le_bon_code(): void
    {
        $this->otp->generate('+224620001001');

        $this->assertTrue($this->otp->verify('+224620001001', '123456'));
    }

    public function test_verify_echoue_avec_un_mauvais_code(): void
    {
        $this->otp->generate('+224620001002');

        $this->assertFalse($this->otp->verify('+224620001002', '000000'));
    }

    public function test_verify_echoue_sans_code_genere(): void
    {
        $this->assertFalse($this->otp->verify('+224620001003', '123456'));
    }

    // ── Usage unique : le code est supprimé après succès ──────────────────────

    public function test_le_code_ne_peut_pas_etre_rejoue_apres_un_succes(): void
    {
        $phone = '+224620001100';
        $this->otp->generate($phone);

        $this->assertTrue($this->otp->verify($phone, '123456'));
        $this->assertFalse($this->otp->verify($phone, '123456'));
    }

    // ── Verrouillage après trop de tentatives ─────────────────────────────────

    public function test_le_code_est_verrouille_apres_5_echecs(): void
    {
        $phone = '+224620001004';
        $this->otp->generate($phone);

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($this->otp->verify($phone, '000000'));
        }

        $this->assertTrue($this->otp->tooManyAttempts($phone));

        // Même le bon code est désormais refusé : il faut en redemander un nouveau.
        $this->assertFalse($this->otp->verify($phone, '123456'));
    }

    public function test_un_echec_ne_declenche_pas_le_verrouillage(): void
    {
        $phone = '+224620001005';
        $this->otp->generate($phone);

        $this->otp->verify($phone, '000000');

        $this->assertFalse($this->otp->tooManyAttempts($phone));
        $this->assertTrue($this->otp->verify($phone, '123456'));
    }

    public function test_un_succes_reinitialise_le_compteur_de_tentatives(): void
    {
        $phone = '+224620001006';
        $this->otp->generate($phone);

        $this->otp->verify($phone, '000000');
        $this->otp->verify($phone, '000000');
        $this->assertTrue($this->otp->verify($phone, '123456'));

        $this->assertFalse($this->otp->tooManyAttempts($phone));
    }

    public function test_generer_un_nouveau_code_reinitialise_le_verrouillage(): void
    {
        $phone = '+224620001007';
        $this->otp->generate($phone);

        for ($i = 0; $i < 5; $i++) {
            $this->otp->verify($phone, '000000');
        }
        $this->assertTrue($this->otp->tooManyAttempts($phone));

        $this->otp->generate($phone);

        $this->assertFalse($this->otp->tooManyAttempts($phone));
        $this->assertTrue($this->otp->verify($phone, '123456'));
    }

    // ── Anti-spam de renvoi (cooldown + plafonds horaire/journalier) ──────────

    public function test_can_send_est_faux_juste_apres_generation(): void
    {
        $phone = '+224620001008';
        $this->otp->generate($phone);

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
            $this->otp->generate($phone);
            // Passe le cooldown de 30s sans sortir de la fenêtre horaire, pour isoler
            // le plafond de 5 envois/heure dans ce test.
            $this->travel(31)->seconds();
        }

        $this->assertFalse($this->otp->canSend($phone));
    }

    // ── clear() ───────────────────────────────────────────────────────────────

    public function test_clear_supprime_le_code_et_les_tentatives(): void
    {
        $phone = '+224620001010';
        $this->otp->generate($phone);
        $this->otp->verify($phone, '000000');

        $this->otp->clear($phone);

        $this->assertFalse($this->otp->verify($phone, '123456'));
        $this->assertFalse($this->otp->tooManyAttempts($phone));
    }

    // ── Contexte : lie un code à une entité précise (ex: une invitation) ──────

    public function test_deux_contextes_differents_ont_des_codes_independants(): void
    {
        $phone = '+224620001011';

        $this->otp->generate($phone, 'invitation-A');
        $this->otp->generate($phone, 'invitation-B');

        // Verrouiller le contexte A n'affecte pas le contexte B.
        for ($i = 0; $i < 5; $i++) {
            $this->otp->verify($phone, '000000', 'invitation-A');
        }
        $this->assertTrue($this->otp->tooManyAttempts($phone, 'invitation-A'));
        $this->assertFalse($this->otp->tooManyAttempts($phone, 'invitation-B'));
        $this->assertTrue($this->otp->verify($phone, '123456', 'invitation-B'));
    }

    public function test_un_code_sans_contexte_nest_pas_valide_avec_un_contexte(): void
    {
        $phone = '+224620001012';
        $this->otp->generate($phone);

        $this->assertFalse($this->otp->verify($phone, '123456', 'invitation-X'));
    }
}
