<?php

return [
    /*
     * Code OTP fixe utilisé en environnement de test/local pour rendre
     * les tests déterministes. Laisser null en production.
     */
    'fixed_code' => env('OTP_FIXED_CODE'),
];
