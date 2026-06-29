<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\App\Resources\Users\UserResource as AppUserResource;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Demo-readiness guard for the two ways a Filament resource 500s on open:
 *  (1) its backing table doesn't exist (e.g. the old SiteSettings bug), and
 *  (2) it's tenant-scoped on a model with no team ownership relationship.
 *
 * Deterministic & light (no Filament HTTP boot). A full authenticated
 * click-through of all 15 resources was verified separately and returns < 500.
 */
class DemoResourcesSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_settings_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('site_settings'), 'site_settings table missing — SiteSettingsResource would 500.');
    }

    public function test_every_filament_resource_is_backed_by_an_existing_table(): void
    {
        $missing = [];

        foreach (['admin', 'app', 'client'] as $panelId) {
            foreach (Filament::getPanel($panelId)->getResources() as $resource) {
                if (! is_subclass_of($resource, Resource::class)) {
                    continue;
                }
                $model = $resource::getModel();
                if (! class_exists($model)) {
                    continue;
                }
                $table = (new $model)->getTable();
                if (! Schema::hasTable($table)) {
                    $missing[] = "[{$panelId}] ".class_basename($resource)." -> {$table}";
                }
            }
        }

        $this->assertSame([], $missing, "Filament resources backed by a missing table (would 500):\n".implode("\n", $missing));
    }

    public function test_app_user_resource_is_not_tenant_scoped(): void
    {
        // The App panel is team-tenant-scoped; User has no `team` ownership
        // relationship, so this resource must opt out or it 500s on open.
        $this->assertFalse(AppUserResource::isScopedToTenant(), 'App UserResource must not be tenant-scoped (User has no team relationship).');
    }

    public function test_every_resource_has_an_index_page(): void
    {
        // Guards the "dead resource" regression: a resource discovered but with
        // no index page has no route and 404s when clicked.
        $orphan = [];

        foreach (['admin', 'app', 'client'] as $panelId) {
            foreach (Filament::getPanel($panelId)->getResources() as $resource) {
                if (! is_subclass_of($resource, Resource::class)) {
                    continue;
                }
                if (! array_key_exists('index', $resource::getPages())) {
                    $orphan[] = "[{$panelId}] ".class_basename($resource);
                }
            }
        }

        $this->assertSame([], $orphan, "Filament resources with no index page (unreachable / 404):\n".implode("\n", $orphan));
    }
}
