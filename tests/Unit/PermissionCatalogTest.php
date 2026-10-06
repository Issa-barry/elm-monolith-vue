<?php

namespace Tests\Unit;

use App\Support\Permissions\PermissionCatalog;
use Tests\TestCase;

class PermissionCatalogTest extends TestCase
{
    /**
     * Garde-fou de la refonte "regroupement métier" de l'écran /backoffice/roles/{role}/edit :
     * si une ressource ou une permission STANDALONE est ajoutée à PermissionCatalog sans être
     * rattachée à un domaine, elle disparaîtrait silencieusement de l'écran d'édition des rôles
     * (Role\EditRoleController → Roles/Edit.vue) au lieu de rester visible/éditable.
     */
    public function test_domains_cover_every_resource_exactly_once(): void
    {
        $seen = [];
        foreach (PermissionCatalog::DOMAINS as $domainKey => $domain) {
            foreach ($domain['resources'] as $resource) {
                $previousDomain = $seen[$resource] ?? '';
                $this->assertArrayNotHasKey(
                    $resource,
                    $seen,
                    "La ressource '{$resource}' apparaît dans plusieurs domaines ('{$previousDomain}' et '{$domainKey}')."
                );
                $seen[$resource] = $domainKey;
            }
        }

        $this->assertEqualsCanonicalizing(
            PermissionCatalog::RESOURCES,
            array_keys($seen),
            'Chaque ressource de PermissionCatalog::RESOURCES doit apparaître dans exactement un domaine.'
        );
    }

    public function test_domains_cover_every_standalone_permission_exactly_once(): void
    {
        $seen = [];
        foreach (PermissionCatalog::DOMAINS as $domainKey => $domain) {
            foreach ($domain['standalone'] as $group => $keys) {
                foreach ($keys as $key) {
                    $previousDomain = $seen[$key] ?? '';
                    $this->assertArrayNotHasKey(
                        $key,
                        $seen,
                        "La permission standalone '{$key}' apparaît dans plusieurs domaines ('{$previousDomain}' et '{$domainKey}/{$group}')."
                    );
                    $seen[$key] = $domainKey;
                }
            }
        }

        $this->assertEqualsCanonicalizing(
            array_keys(PermissionCatalog::STANDALONE),
            array_keys($seen),
            'Chaque permission de PermissionCatalog::STANDALONE doit apparaître dans exactement un domaine.'
        );
    }

    /**
     * Chaque sous-groupe de `standalone` (ex. "Cycle de vente", "Facturation") ne doit contenir
     * que des clés réellement définies dans PermissionCatalog::STANDALONE — un nom mal orthographié
     * lors de l'ajout d'un sous-groupe échouerait silencieusement le test de couverture ci-dessus
     * (la clé fantôme ne matcherait simplement jamais rien), sans jamais le signaler explicitement.
     */
    public function test_domain_standalone_groups_only_reference_known_permissions(): void
    {
        $known = array_keys(PermissionCatalog::STANDALONE);

        foreach (PermissionCatalog::DOMAINS as $domainKey => $domain) {
            foreach ($domain['standalone'] as $group => $keys) {
                foreach ($keys as $key) {
                    $this->assertContains(
                        $key,
                        $known,
                        "Le sous-groupe '{$group}' du domaine '{$domainKey}' référence '{$key}', absente de PermissionCatalog::STANDALONE."
                    );
                }
            }
        }
    }

    public function test_domains_for_filters_resources_but_keeps_standalone(): void
    {
        $visible = array_values(array_filter(PermissionCatalog::RESOURCES, fn ($r) => $r !== 'users'));

        $domains = PermissionCatalog::domainsFor($visible);

        $this->assertArrayHasKey('administration', $domains);
        $this->assertNotContains('users', $domains['administration']['resources']);
        $this->assertContains('parametres-systeme', $domains['administration']['resources']);

        // Une permission standalone sans ressource dans le domaine (ex. Communications) reste
        // présente même si le domaine n'a aucune ressource CRUD.
        $this->assertArrayHasKey('communications', $domains);
        $this->assertSame([], $domains['communications']['resources']);
        $this->assertNotEmpty($domains['communications']['standalone']);
    }

    public function test_domains_for_drops_domains_left_with_nothing_to_show(): void
    {
        $domains = PermissionCatalog::domainsFor([]);

        foreach ($domains as $domain) {
            $this->assertTrue(
                $domain['resources'] !== [] || $domain['standalone'] !== [],
                'Un domaine sans ressource visible ni permission standalone ne doit pas être renvoyé.'
            );
        }
    }
}
