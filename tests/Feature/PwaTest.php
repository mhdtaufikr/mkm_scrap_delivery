<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifests_have_different_ids_and_non_overlapping_scopes_and_valid_icons(): void
    {
        $general = $this->get('/app/manifest.webmanifest')->assertOk()->assertHeader('Content-Type', 'application/manifest+json')->json();
        $security = $this->get('/security/manifest.webmanifest')->assertOk()->json();
        $this->assertNotSame($general['id'], $security['id']);
        $this->assertSame('MKM Scrap Delivery', $general['name']);
        $this->assertSame('MKM Security Gate', $security['name']);
        $this->assertStringEndsWith('/app/', $general['scope']);
        $this->assertStringEndsWith('/security/', $security['scope']);
        foreach ([$general, $security] as $m) {
            $this->assertStringStartsWith($m['scope'], $m['start_url']);
            $this->assertSame('standalone', $m['display']);
            foreach ($m['icons'] as $icon) {
                $size = getimagesize(public_path(parse_url($icon['src'], PHP_URL_PATH)));
                $this->assertSame($icon['sizes'], $size[0].'x'.$size[1]);
            }
        }
    }

    public function test_each_worker_has_correct_allowed_scope_and_cache_namespace_and_online_only_posts(): void
    {
        $workers = [];
        foreach (['general' => 'app', 'security' => 'security'] as $kind => $prefix) {
            $r = $this->get('/'.$prefix.'/sw.js')->assertOk()->assertHeader('Service-Worker-Allowed', '/'.$prefix.'/')->assertSee("request.method !== 'GET'", false)->assertDontSee('sync.addEventListener', false);
            $this->assertStringContainsString('application/javascript', $r->headers->get('Content-Type'));
            $workers[$kind] = $r->getContent();
            $this->assertStringContainsString('No account or approval data is cached', $workers[$kind]);
        }
        $this->assertNotSame($workers['general'], $workers['security']);
    }

    public function test_install_pages_are_public_and_use_their_own_manifest_and_offline_page_has_no_forms(): void
    {
        foreach (['app' => 'MKM Scrap Delivery', 'security' => 'MKM Security Gate'] as $prefix => $name) {
            $this->get('/'.$prefix.'/install')->assertOk()->assertSee($name)->assertSee('/'.$prefix.'/manifest.webmanifest')->assertSee('data-pwa-install', false);
            $this->get('/'.$prefix.'/offline')->assertOk()->assertSee('Connection required')->assertDontSee('<form', false)->assertDontSee('csrf', false);
        }
    }

    public function test_generated_general_routes_stay_inside_app_scope_and_legacy_paths_still_work(): void
    {
        foreach (['dashboard', 'login', 'users.index', 'roles.index', 'approvals.index', 'security-admin.index', 'access.show'] as $name) {
            $this->assertStringContainsString('/app/', route($name));
        }
        $this->assertStringEndsWith('/security/gate', route('security.index'));
        $u = User::factory()->create(['role' => 'ADMIN']);
        $this->actingAs($u)->get('/home')->assertOk();
        $this->get('/app/home')->assertOk();
        $this->get('/MKM@dm1n')->assertOk();
        $this->assertGuest();
        $this->get('/app/home')->assertRedirect(route('login'));
    }
}
