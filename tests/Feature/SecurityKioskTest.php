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

    private function document(string $status = 'SECURITY'): array
    {
        static $sequence = 0;
        $sequence++;
        $path = 'handover/test-'.$sequence.'.pdf';
        Storage::disk('local')->put($path, 'signed evidence');
        $document = DeliveryDocument::create([
            'number' => 'SMB-SECURITY-'.$sequence, 'type' => 'SCRAP', 'status' => $status,
            'created_by' => $this->owner->id, 'document_date' => today(), 'driver_name' => 'Driver Test',
            'company' => 'Buyer', 'destination' => 'Jakarta', 'vehicle' => 'Truck', 'plate_number' => 'B 123',
            'goods_description' => 'Scrap', 'handover_path' => $path, 'receiver_name' => 'Receiver',
            'approval_snapshot' => app(ApprovalConfiguration::class)->active('SCRAP'),
        ]);
        $code = $document->issueSecurityCode();

        return [$document->fresh(), $code];
    }

    private function payload(DeliveryDocument $document): array
    {
        return ['inspection_version' => $document->inspectionVersion(), 'security_name' => 'Budi Security', 'goods_checked' => 1, 'vehicle_checked' => 1, 'documents_checked' => 1];
    }

    public function test_gate_is_public_but_does_not_list_documents(): void
    {
        [$document] = $this->document();
        $this->get(route('security.index'))->assertOk()->assertSee('No account or login is required')->assertDontSee($document->number);
        $this->assertGuest();
        $this->get(route('security.show', $document))->assertForbidden();
        $this->get(route('security.evidence', $document))->assertForbidden();
    }

    public function test_exact_unique_number_opens_only_its_document(): void
    {
        [$document, $code] = $this->document();
        $this->post(route('security.lookup'), ['security_code' => 'WRONG-CODE-0000'])->assertSessionHasErrors('security_code');
        $this->post(route('security.lookup'), ['security_code' => strtolower(str_replace('-', '', $code))])->assertRedirect(route('security.show', $document));
        $this->get(route('security.show', $document))->assertOk()->assertSee($document->number)->assertSee('B 123')->assertSee('Actual officer on duty');
        $this->get(route('security.evidence', $document))->assertOk();
        $this->assertGuest();
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
        $this->assertSame('SECURITY', $document->fresh()->status);
    }

    public function test_validation_completes_release_records_name_and_closes_code(): void
    {
        [$document, $code] = $this->document();
        $this->post(route('security.lookup'), ['security_code' => $code]);
        $this->post(route('security.approve', $document), $this->payload($document))->assertRedirect(route('security.show', $document));
        $this->assertSame('COMPLETED', $document->fresh()->status);
        $this->assertNotNull($document->fresh()->security_code_used_at);
        $this->assertDatabaseHas('document_events', ['delivery_document_id' => $document->id, 'user_id' => $this->gate->id, 'action' => 'release', 'security_officer_name' => 'Budi Security', 'actual_actor_name' => 'Budi Security']);
        $this->get(route('security.show', $document))->assertOk()->assertSee('Goods release completed')->assertSee('Budi Security');
        $this->post(route('security.approve', $document), $this->payload($document->fresh()))->assertStatus(409);
        $this->withSession([])->post(route('security.lookup'), ['security_code' => $code])->assertSessionHasErrors('security_code');
    }

    public function test_stale_document_wrong_status_and_missing_evidence_cannot_release(): void
    {
        [$document, $code] = $this->document();
        $this->post(route('security.lookup'), ['security_code' => $code]);
        $stale = $this->payload($document);
        $document->events()->create(['user_id' => $this->owner->id, 'actor_role' => 'PPC', 'action' => 'updated', 'to_status' => 'SECURITY']);
        $this->post(route('security.approve', $document), $stale)->assertStatus(409);

        [$missing, $missingCode] = $this->document();
        Storage::disk('local')->delete($missing->handover_path);
        $this->post(route('security.lookup'), ['security_code' => $missingCode]);
        $this->post(route('security.approve', $missing), $this->payload($missing->fresh()))->assertSessionHasErrors('evidence');

        [$upstream, $upstreamCode] = $this->document('HRD');
        $this->post(route('security.lookup'), ['security_code' => $upstreamCode])->assertSessionHasErrors('security_code');
    }
}
