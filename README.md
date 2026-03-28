 # Deploiement pre-prod
 cd ~/domains/eau-la-maman.fr/public_html
composer2 update
php artisan migrate:fresh --seed
php artisan optimize:clear
php artisan optimize


php artisan storage:link
php artisan db:seed --class=FakeDataSeeder


# Playwright est en place et fonctionnel.

npm run e2e
Lance les tests Playwright en mode normal (headless, rapide, idéal CI).

npm run e2e:headed
Lance les tests avec navigateur visible (utile pour voir le comportement à l’écran).

npm run e2e:ui
Ouvre l’interface Playwright Test UI pour lancer/debugger test par test (très pratique en dev).

npm run e2e:report
Ouvre le rapport HTML du dernier run (résultats, traces, screenshots, vidéos).




 