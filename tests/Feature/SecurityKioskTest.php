<?php

namespace Tests\Feature;

use App\Models\DeliveryDocument;
use App\Models\SecurityDevice;
use App\Models\SecurityOfficer;
use App\Models\User;
use App\Services\ApprovalConfiguration;
use App\Services\SecurityAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecurityKioskTest extends TestCase
{
    use RefreshDatabase;

    private string $credential;

    private SecurityDevice $device;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        $this->credential = '1.'.str_repeat('A', 64);
        $this->device = SecurityDevice::create(['name' => 'Security Pos 1', 'token_hash' => hash('sha256', str_repeat('A', 64)), 'token_expires_at' => now()->addDays(90)]);
    }

    private function officer(): SecurityOfficer
    {
        $u = User::factory()->create(['name' => 'Officer Test', 'role' => 'SECURITY', 'local_login_enabled' => false]);

        return SecurityOfficer::create(['user_id' => $u->id, 'pin_hash' => Hash::make('683729'), 'pin_changed_at' => now()]);
    }

    private function document(string $status = 'SECURITY'): DeliveryDocument
    {
        $u = User::factory()->create(['role' => 'PPC']);
        Storage::disk('local')->put('handover/test.pdf', 'signed evidence');

        return DeliveryDocument::create(['number' => 'SMB-SECURITY', 'type' => 'SCRAP', 'status' => $status, 'created_by' => $u->id, 'document_date' => today(), 'driver_name' => 'Driver Test', 'company' => 'Buyer', 'destination' => 'Jakarta', 'vehicle' => 'Truck', 'plate_number' => 'B 123', 'goods_description' => 'Scrap', 'handover_path' => 'handover/test.pdf', 'receiver_name' => 'Receiver', 'approval_snapshot' => app(ApprovalConfiguration::class)->active('SCRAP')]);
    }

    private function browser(): self
    {
        return $this->withCookie(SecurityAccess::COOKIE, $this->credential);
    }

    private function payload(DeliveryDocument $doc, SecurityOfficer $officer): array
    {
        return ['officer_id' => $officer->id, 'pin' => '683729', 'inspection_version' => $doc->inspectionVersion(), 'goods_checked' => 1, 'vehicle_checked' => 1];
    }

    public function test_unpaired_browser_cannot_read_or_approve_even_with_correct_pin(): void
    {
        $o = $this->officer();
        $d = $this->document();
        $this->get('/security')->assertRedirect(route('security.activate'));
        $this->get(route('security.show', $d))->assertRedirect(route('security.activate'));
        $this->post(route('security.approve', $d), $this->payload($d, $o))->assertRedirect(route('security.activate'));
        $this->assertSame('SECURITY', $d->fresh()->status);
    }

    public function test_one_time_activation_code_is_consumed_and_browser_cookie_is_private(): void
    {
        $code = app(SecurityAccess::class)->activation($this->device);
        $response = $this->post('/security/activate', ['activation_code' => $code]);
        $response->assertRedirect(route('security.index'))->assertCookie(SecurityAccess::COOKIE);
        $this->assertNull($this->device->fresh()->activation_hash);
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === SecurityAccess::COOKIE);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());
        $this->post('/security/activate', ['activation_code' => $code])->assertSessionHasErrors('activation_code')->assertSessionMissing('_old_input.activation_code');
    }

    public function test_activation_rate_limit_is_independent_of_inspection_traffic(): void
    {
        $d = $this->document();
        for ($i = 0; $i < 5; $i++) {
            $this->browser()->post(route('security.approve', $d), [])->assertSessionHasErrors('pin');
        }
        $code = app(SecurityAccess::class)->activation($this->device);
        $this->post('/security/activate', ['activation_code' => $code])->assertRedirect(route('security.index'));
    }

    public function test_expired_activation_and_tampered_expired_or_revoked_credentials_are_rejected(): void
    {
        $code = app(SecurityAccess::class)->activation($this->device);
        $this->device->update(['activation_expires_at' => now()->subMinute()]);
        $this->post('/security/activate', ['activation_code' => $code])->assertSessionHasErrors('activation_code');
        $this->withCookie(SecurityAccess::COOKIE, '1.'.str_repeat('B', 64))->get('/security')->assertRedirect(route('security.activate'));
        $this->device->update(['token_hash' => hash('sha256', str_repeat('A', 64)), 'token_expires_at' => now()->subMinute()]);
        $this->browser()->get('/security')->assertRedirect(route('security.activate'));
        $this->device->update(['token_expires_at' => now()->addDay(), 'is_active' => false]);
        $this->browser()->get('/security')->assertRedirect(route('security.activate'));
    }

    public function test_registered_browser_uses_no_application_login_and_pin_approval_records_officer_and_device(): void
    {
        $o = $this->officer();
        $d = $this->document();
        $this->browser()->get('/security')->assertOk()->assertSee('SMB-SECURITY');
        $this->assertGuest();
        $this->get(route('security.show', $d))->assertOk()->assertSee('Officer Test')->assertSee('Approve Goods Release');
        $this->get(route('security.evidence', $d))->assertOk();
        $payload = $this->payload($d, $o);
        $this->post(route('security.approve', $d), $payload)->assertRedirect(route('security.show', $d));
        $this->assertSame('COMPLETED', $d->fresh()->status);
        $this->assertGuest();
        $this->assertDatabaseHas('document_events', ['delivery_document_id' => $d->id, 'user_id' => $o->user_id, 'action' => 'release', 'security_device_id' => $this->device->id, 'security_device_name' => 'Security Pos 1', 'security_officer_name' => 'Officer Test']);
        $this->get(route('security.show', $d))->assertOk()->assertSee('Goods release approved')->assertSee('Officer Test')->assertDontSee('name="pin"', false);
        $this->post(route('security.approve', $d), $payload)->assertStatus(409);
        $this->assertSame(1, $d->events()->where('action', 'release')->count());
        $this->get('/users')->assertRedirect(route('login'));
        $this->get('/home')->assertRedirect(route('login'));
    }

    public function test_pin_officer_cannot_bypass_pin_via_the_normal_document_action(): void
    {
        $o = $this->officer();
        $d = $this->document();
        $this->actingAs($o->user)->withSession(['auth_method' => 'sso'])->post(route('documents.transition', $d), ['action' => 'release', 'confirmed' => 1])->assertForbidden();
        $this->assertSame('SECURITY', $d->fresh()->status);
    }

    public function test_wrong_pin_never_releases_and_is_not_flashed_then_officer_is_rate_limited(): void
    {
        $o = $this->officer();
        $d = $this->document();
        $payload = [...$this->payload($d, $o), 'pin' => '000000'];
        for ($i = 0; $i < 5; $i++) {
            $this->browser()->post(route('security.approve', $d), $payload)->assertSessionHasErrors('pin')->assertSessionMissing('_old_input.pin');
        }
        $this->post(route('security.approve', $d), $this->payload($d, $o))->assertSessionHasErrors('pin');
        $this->assertSame('SECURITY', $d->fresh()->status);
        $this->assertSame(0, $d->events()->count());
    }

    public function test_missing_evidence_inactive_officer_or_upstream_status_blocks_release(): void
    {
        $o = $this->officer();
        $d = $this->document('HRD');
        $this->browser()->post(route('security.approve', $d), $this->payload($d, $o))->assertForbidden();
        $d->update(['status' => 'SECURITY']);
        $o->update(['is_active' => false]);
        $this->post(route('security.approve', $d), $this->payload($d, $o))->assertSessionHasErrors('pin');
        $o->update(['is_active' => true]);
        Storage::disk('local')->delete('handover/test.pdf');
        $this->post(route('security.approve', $d), $this->payload($d, $o))->assertSessionHasErrors('evidence');
        $this->assertSame('SECURITY', $d->fresh()->status);
    }

    public function test_physical_checks_and_latest_document_version_are_required(): void
    {
        $o = $this->officer();
        $d = $this->document();
        $payload = $this->payload($d, $o);
        unset($payload['goods_checked']);
        $this->browser()->post(route('security.approve', $d), $payload)->assertSessionHasErrors('goods_checked');
        $payload = $this->payload($d, $o);
        $d->events()->create(['user_id' => $d->created_by, 'actor_role' => 'PPC', 'action' => 'updated', 'to_status' => 'SECURITY']);
        $this->post(route('security.approve', $d), $payload)->assertStatus(409);
        $d->update(['type' => 'DISPOSAL']);
        $this->get(route('security.show', $d))->assertNotFound();
    }

    public function test_only_admin_manages_device_and_officer_and_pin_reset_revokes_previous_pin(): void
    {
        $viewer = User::factory()->create(['role' => 'VIEWER']);
        $this->actingAs($viewer)->get(route('security-admin.index'))->assertForbidden();
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $this->actingAs($admin)->get(route('security-admin.index'))->assertOk();
        $this->post(route('security-admin.officers.store'), ['name' => 'New Officer'])->assertSessionHas('officer_pin');
        $pin = session('officer_pin');
        $o = SecurityOfficer::firstOrFail();
        $this->assertTrue(Hash::check($pin, $o->pin_hash));
        $this->assertFalse($o->user->local_login_enabled);
        $this->assertSame('SECURITY', $o->user->role);
        $this->post(route('security-admin.officers.pin', $o))->assertSessionHas('officer_pin');
        $newPin = session('officer_pin');
        $this->assertTrue(Hash::check($newPin, $o->fresh()->pin_hash));
        $this->post(route('security-admin.devices.revoke', $this->device))->assertRedirect();
        $this->browser()->get('/security')->assertRedirect(route('security.activate'));
    }
}
