<?php

namespace Tests\Feature;

use App\Models\DeliveryDocument;
use App\Models\User;
use App\Services\ApprovalConfiguration;
use Database\Seeders\ApprovalFlowSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecurityKioskTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $gate;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        $this->seed(ApprovalFlowSeeder::class);
        $this->owner = User::factory()->create(['role' => 'PPC']);
        $this->gate = User::factory()->create(['name' => 'Security Gate System', 'username' => 'security.gate', 'email' => 'security-gate@system.local', 'role' => 'SECURITY', 'local_login_enabled' => false]);
    }

    private function document(string $status = 'DRIVER_HANDOVER'): array
    {
        static $sequence = 0;
        $sequence++;
        $snapshot = app(ApprovalConfiguration::class)->active('SCRAP');
        $snapshot['steps']['DRIVER_HANDOVER'] = [
            'code' => 'DRIVER_HANDOVER', 'name' => 'Pemeriksaan Security',
            'actor_mode' => 'role', 'role_code' => 'SECURITY', 'user_ids' => [], 'action' => 'release',
            'action_label' => 'Selesaikan pemeriksaan', 'next_code' => 'COMPLETED', 'delegate_to' => null,
            'notice_roles' => [], 'delegate_notice_roles' => [], 'allow_return' => false,
        ];
        $document = DeliveryDocument::create([
            'number' => 'SMB-SECURITY-'.$sequence, 'type' => 'SCRAP', 'status' => $status,
            'created_by' => $this->owner->id, 'document_date' => today(), 'driver_name' => '',
            'company' => 'Buyer', 'destination' => 'Jakarta', 'vehicle' => 'Truck', 'plate_number' => 'B 123',
            'goods_description' => 'Scrap', 'approval_snapshot' => $snapshot,
        ]);
        $code = $document->issueSecurityCode();

        return [$document->fresh(), $code];
    }

    private function payload(DeliveryDocument $document): array
    {
        return ['inspection_version' => $document->inspectionVersion(), 'security_name' => 'Budi Security', 'driver_name' => 'Joko Driver', 'goods_checked' => 1, 'vehicle_checked' => 1, 'documents_checked' => 1];
    }

    public function test_gate_live_dashboard_separates_pending_approval_from_security_queue(): void
    {
        [$ready] = $this->document();
        [$gate] = $this->document();
        [$pending] = $this->document('HRA_APPROVAL');
        [$completed] = $this->document('COMPLETED');

        $this->get(route('security.index'))->assertOk()
            ->assertSee('Lihat proses approval')->assertSee('Siap diperiksa Security')->assertSee('Kode unik')
            ->assertDontSee('Pindai QR Security')->assertDontSee('data-security-scan', false)
            ->assertDontSee('Menunggu TTD')
            ->assertSee($ready->number)->assertSee($gate->number)
            ->assertSee($pending->number)->assertDontSee($completed->number);
        $dashboard = $this->getJson(route('security.dashboard'))->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('refreshed_at', fn ($value) => is_string($value));
        $html = $dashboard->json('html');
        $this->assertStringContainsString($ready->number, $html);
        $this->assertStringContainsString($gate->number, $html);
        $this->assertStringContainsString($pending->number, $html);
        $this->assertStringNotContainsString($completed->number, $html);
        $this->assertGuest();
        $this->get(route('security.show', $gate))->assertForbidden();
        $this->get(route('security.evidence', $gate))->assertForbidden();
    }

    public function test_exact_unique_number_opens_only_its_document(): void
    {
        [$document, $code] = $this->document();
        $this->assertSame(6, strlen($code));
        $this->post(route('security.lookup'), ['security_code' => 'WRONG1'])->assertSessionHasErrors('security_code');
        $this->post(route('security.lookup'), ['security_code' => strtolower($code)])->assertRedirect(route('security.show', $document));
        $this->get(route('security.show', $document))->assertOk()->assertSee($document->number)->assertSee('B 123')->assertSee('Nama petugas yang berjaga')->assertSee('Nama lengkap pengemudi');
        $this->get(route('security.evidence', $document))->assertNotFound();
        $this->assertGuest();
    }

    public function test_official_prints_do_not_embed_gate_code_or_qr(): void
    {
        [$document, $code] = $this->document();
        $this->actingAs($this->owner);
        foreach (['smb', 'delivery'] as $template) {
            $this->get(route('documents.print', [$document, 'template' => $template]))->assertOk()
                ->assertDontSee('PINDAI DI POS SECURITY')->assertDontSee($code)->assertDontSee('data:image/svg+xml;base64,', false);
        }
        $this->get(route('documents.print', [$document, 'template' => 'security-slip']))->assertSessionHasErrors('template');
        $this->post(route('security.lookup'), ['security_code' => $code])->assertRedirect(route('security.show', $document));
        $this->post(route('security.approve', $document), $this->payload($document))->assertRedirect(route('security.show', $document));
    }

    public function test_physical_checks_and_actual_security_name_are_required(): void
    {
        [$document, $code] = $this->document();
        $this->post(route('security.lookup'), ['security_code' => $code]);
        $payload = $this->payload($document);
        unset($payload['goods_checked']);
        $this->post(route('security.approve', $document), $payload)->assertSessionHasErrors('goods_checked');
        $payload = $this->payload($document);
        $payload['security_name'] = '';
        $this->post(route('security.approve', $document), $payload)->assertSessionHasErrors('security_name');
        $payload = $this->payload($document);
        $payload['driver_name'] = '';
        $this->post(route('security.approve', $document), $payload)->assertSessionHasErrors('driver_name');
        $this->assertSame('DRIVER_HANDOVER', $document->fresh()->status);
    }

    public function test_validation_completes_release_records_name_and_closes_code(): void
    {
        [$document, $code] = $this->document();
        $this->post(route('security.lookup'), ['security_code' => $code]);
        $this->post(route('security.approve', $document), $this->payload($document))->assertRedirect(route('security.show', $document));
        $this->assertSame('COMPLETED', $document->fresh()->status);
        $this->assertNotNull($document->fresh()->security_code_used_at);
        $this->assertDatabaseHas('document_events', ['delivery_document_id' => $document->id, 'user_id' => $this->gate->id, 'action' => 'release', 'security_officer_name' => 'Budi Security', 'actual_actor_name' => 'Budi Security']);
        $this->assertSame('Joko Driver', $document->fresh()->driver_name);
        $this->assertNull($document->fresh()->driver_signed_at);
        $this->get(route('security.show', $document))->assertOk()->assertSee('Pengeluaran barang sudah selesai')->assertSee('Budi Security')->assertSee('Joko Driver');
        $this->post(route('security.approve', $document), $this->payload($document->fresh()))->assertStatus(409);
        $this->withSession([])->post(route('security.lookup'), ['security_code' => $code])->assertSessionHasErrors('security_code');
    }

    public function test_stale_document_and_wrong_status_cannot_release_but_signature_evidence_is_not_required(): void
    {
        [$document, $code] = $this->document();
        $this->post(route('security.lookup'), ['security_code' => $code]);
        $stale = $this->payload($document);
        $document->events()->create(['user_id' => $this->owner->id, 'actor_role' => 'PPC', 'action' => 'updated', 'to_status' => 'DRIVER_HANDOVER']);
        $this->post(route('security.approve', $document), $stale)->assertStatus(409);

        [$unsigned, $unsignedCode] = $this->document();
        $this->post(route('security.lookup'), ['security_code' => $unsignedCode]);
        $this->post(route('security.approve', $unsigned), $this->payload($unsigned->fresh()))->assertRedirect(route('security.show', $unsigned));
        $this->assertSame('COMPLETED', $unsigned->fresh()->status);

        [$upstream, $upstreamCode] = $this->document('HRD');
        $this->post(route('security.lookup'), ['security_code' => $upstreamCode])->assertSessionHasErrors('security_code');
    }
}
