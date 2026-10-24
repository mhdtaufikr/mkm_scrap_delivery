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

    private function document(string $status = 'DRIVER_HANDOVER', bool $signed = true): array
    {
        static $sequence = 0;
        $sequence++;
        $path = 'handover/test-'.$sequence.'.pdf';
        Storage::disk('local')->put($path, 'signed evidence');
        $snapshot = app(ApprovalConfiguration::class)->active('SCRAP');
        $snapshot['steps']['DRIVER_HANDOVER'] = [
            'code' => 'DRIVER_HANDOVER', 'name' => 'Tanda Tangan Pengemudi & Pemeriksaan Security',
            'actor_mode' => 'role', 'role_code' => 'SECURITY', 'user_ids' => [], 'action' => 'release',
            'action_label' => 'Selesaikan pemeriksaan', 'next_code' => 'COMPLETED', 'delegate_to' => null,
            'notice_roles' => [], 'delegate_notice_roles' => [], 'allow_return' => false,
        ];
        $document = DeliveryDocument::create([
            'number' => 'SMB-SECURITY-'.$sequence, 'type' => 'SCRAP', 'status' => $status,
            'created_by' => $this->owner->id, 'document_date' => today(), 'driver_name' => 'Driver Test',
            'company' => 'Buyer', 'destination' => 'Jakarta', 'vehicle' => 'Truck', 'plate_number' => 'B 123',
            'goods_description' => 'Scrap', 'handover_path' => $signed ? $path : null, 'receiver_name' => $signed ? 'Driver Test' : null,
            'driver_signed_name' => $signed ? 'Driver Test' : null, 'driver_signature_path' => $signed ? $path : null,
            'driver_signed_at' => $signed ? now() : null, 'approval_snapshot' => $snapshot,
        ]);
        $code = $document->issueSecurityCode();

        return [$document->fresh(), $code];
    }

    private function payload(DeliveryDocument $document): array
    {
        return ['inspection_version' => $document->inspectionVersion(), 'security_name' => 'Budi Security', 'goods_checked' => 1, 'vehicle_checked' => 1, 'documents_checked' => 1];
    }

    public function test_gate_live_dashboard_separates_pending_approval_from_drivers_ready_to_call(): void
    {
        [$ready] = $this->document('DRIVER_HANDOVER', false);
        [$gate] = $this->document();
        [$pending] = $this->document('HRA_APPROVAL');
        [$completed] = $this->document('COMPLETED');

        $this->get(route('security.index'))->assertOk()
            ->assertSee('Lihat proses approval')->assertSee('Pengemudi siap dipanggil')->assertSee('Pindai QR Security')->assertSee('data-security-scan', false)
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
        $this->post(route('security.lookup'), ['security_code' => 'WRONG-CODE-0000'])->assertSessionHasErrors('security_code');
        $this->post(route('security.lookup'), ['security_code' => strtolower(str_replace('-', '', $code))])->assertRedirect(route('security.show', $document));
        $this->get(route('security.show', $document))->assertOk()->assertSee($document->number)->assertSee('B 123')->assertSee('Nama petugas yang berjaga');
        $this->get(route('security.evidence', $document))->assertOk();
        $this->assertGuest();
    }

    public function test_security_qr_is_printed_only_while_code_is_active(): void
    {
        [$document, $code] = $this->document();
        $this->actingAs($this->owner);
        foreach (['smb', 'delivery'] as $template) {
            $this->get(route('documents.print', [$document, 'template' => $template]))->assertOk()
                ->assertSee('PINDAI DI POS SECURITY')->assertSee($code)->assertSee('data:image/svg+xml;base64,', false);
        }
        $this->get(route('documents.print', [$document, 'template' => 'release']))->assertOk()->assertDontSee('PINDAI DI POS SECURITY');
        $this->post(route('security.lookup'), ['security_code' => 'MKM-SMB:'.$code])->assertRedirect(route('security.show', $document));
        $this->post(route('security.approve', $document), $this->payload($document))->assertRedirect(route('security.show', $document));
        $this->get(route('documents.print', [$document->fresh(), 'template' => 'smb']))->assertOk()->assertDontSee('PINDAI DI POS SECURITY');
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
        $this->get(route('security.show', $document))->assertOk()->assertSee('Pengeluaran barang sudah selesai')->assertSee('Budi Security');
        $this->post(route('security.approve', $document), $this->payload($document->fresh()))->assertStatus(409);
        $this->withSession([])->post(route('security.lookup'), ['security_code' => $code])->assertSessionHasErrors('security_code');
    }

    public function test_stale_document_wrong_status_and_missing_evidence_cannot_release(): void
    {
        [$document, $code] = $this->document();
        $this->post(route('security.lookup'), ['security_code' => $code]);
        $stale = $this->payload($document);
        $document->events()->create(['user_id' => $this->owner->id, 'actor_role' => 'PPC', 'action' => 'updated', 'to_status' => 'DRIVER_HANDOVER']);
        $this->post(route('security.approve', $document), $stale)->assertStatus(409);

        [$missing, $missingCode] = $this->document();
        Storage::disk('local')->delete($missing->handover_path);
        $this->post(route('security.lookup'), ['security_code' => $missingCode]);
        $this->post(route('security.approve', $missing), $this->payload($missing->fresh()))->assertSessionHasErrors('evidence');

        [$upstream, $upstreamCode] = $this->document('HRD');
        $this->post(route('security.lookup'), ['security_code' => $upstreamCode])->assertSessionHasErrors('security_code');
    }
}
