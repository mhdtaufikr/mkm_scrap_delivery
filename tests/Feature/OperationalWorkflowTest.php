<?php

namespace Tests\Feature;

use App\Models\DeliveryDocument;
use App\Models\User;
use App\Services\DocumentWorkflow;
use Database\Seeders\OperationalSetupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OperationalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_can_save_a_quiet_draft_or_create_and_send_in_one_action(): void
    {
        Notification::fake();
        config(['workflow.initial_password' => 'Temporary#2026']);
        $this->seed(OperationalSetupSeeder::class);
        $owner = User::where('username', 'mkm01868')->firstOrFail();
        $payload = [
            'type' => 'SCRAP', 'document_date' => '2026-10-20', 'driver_name' => 'Driver',
            'company' => 'Buyer', 'destination' => 'Bekasi', 'vehicle' => 'Truck',
            'plate_number' => 'B 1234 MKM', 'items' => [[
                'code' => 'SCR-001', 'name' => 'Steel Scrap', 'quantity' => '1.25', 'unit' => 'KG',
            ]],
        ];

        $this->actingAs($owner)->post(route('documents.store'), [...$payload, 'submission_intent' => 'draft'])->assertRedirect();
        $draft = DeliveryDocument::latest('id')->firstOrFail();
        $this->assertSame('DRAFT', $draft->status);
        Notification::assertNothingSent();

        $this->post(route('documents.store'), [...$payload, 'submission_intent' => 'submit'])->assertRedirect();
        $submitted = DeliveryDocument::latest('id')->firstOrFail();
        $this->assertSame('SALES_APPROVAL', $submitted->status);
        $this->assertDatabaseHas('document_events', [
            'delivery_document_id' => $submitted->id, 'action' => 'submit',
            'from_status' => 'DRAFT', 'to_status' => 'SALES_APPROVAL',
        ]);
    }

    public function test_named_accounts_and_complete_operational_flow_are_ready(): void
    {
        config(['workflow.initial_password' => 'Temporary#2026']);
        $this->seed(OperationalSetupSeeder::class);

        $expected = [
            'mkm01868' => 'PPC', 'mkm01576' => 'PPC', 'mkm02099' => 'PPC_SH', 'mkm01727' => 'PPC_DH',
            'mkm01478' => 'WAREHOUSE_PICKUP', 'mkm02119' => 'SALES', 'mkm01185' => 'SALES_DH',
            'mkm02088' => 'SALES_SH', 'mkm001531' => 'HRA_DH', 'mkm01436' => 'HRA_SH',
        ];
        foreach ($expected as $username => $role) {
            $this->assertDatabaseHas('users', ['username' => $username, 'role' => $role, 'local_login_enabled' => true, 'must_change_password' => false]);
        }

        $owner = User::where('username', 'mkm01868')->firstOrFail();
        $document = DeliveryDocument::create([
            'number' => 'SMB-OPERATIONAL', 'created_by' => $owner->id, 'type' => 'SCRAP', 'status' => 'DRAFT',
            'document_date' => today(), 'driver_name' => 'Driver', 'company' => 'Buyer', 'destination' => 'Jakarta',
            'vehicle' => 'Truck', 'plate_number' => 'B 2026 MKM', 'goods_description' => 'Scrap',
        ]);
        $workflow = app(DocumentWorkflow::class);
        $workflow->transition($document, $owner, 'submit');
        $this->assertSame('SALES_APPROVAL', $document->fresh()->status);
        $this->assertNotNull($document->fresh()->securityCode());
        $this->assertSame(6, strlen($document->fresh()->securityCode()));
        $this->actingAs($owner)->get(route('documents.print', [$document->fresh(), 'template' => 'smb']))->assertOk()
            ->assertDontSee('PINDAI DI POS SECURITY')->assertDontSee($document->fresh()->securityCode());
        $this->post(route('security.lookup'), ['security_code' => $document->fresh()->securityCode()])
            ->assertRedirect(route('security.show', $document));
        $this->get(route('security.show', $document))->assertOk()->assertSee('Persetujuan belum selesai');
        $workflow->transition($document, User::where('username', 'mkm02119')->firstOrFail(), 'approve');
        $this->assertSame('HRA_APPROVAL', $document->fresh()->status);
        $workflow->transition($document, User::where('username', 'mkm01436')->firstOrFail(), 'approve');
        $this->assertSame('DRIVER_HANDOVER', $document->fresh()->status);

        $this->get(route('security.show', $document))->assertOk()
            ->assertSee('Nama lengkap pengemudi')->assertSee('Diisi oleh petugas Security')
            ->assertDontSee('Tanda tangan pengemudi')->assertDontSee('data:image/svg+xml;base64,', false);
        $this->get('/driver-signature/obsolete-token')->assertNotFound();
        $document->refresh();
        $this->get(route('security.show', $document))->assertOk()->assertSee('Beri stempel digital &amp; selesaikan', false);
        $this->post(route('security.approve', $document), [
            'inspection_version' => $document->inspectionVersion(), 'security_name' => 'Security Actual', 'driver_name' => 'Driver Actual',
            'goods_checked' => 1, 'vehicle_checked' => 1, 'documents_checked' => 1,
        ])->assertRedirect(route('security.show', $document));
        $this->assertSame('COMPLETED', $document->fresh()->status);
        $this->assertDatabaseHas('document_events', ['delivery_document_id' => $document->id, 'security_officer_name' => 'Security Actual', 'to_status' => 'COMPLETED']);
        $this->assertSame('Security Actual', $document->fresh()->seal_actual_name);
        $this->assertSame('Driver Actual', $document->fresh()->driver_name);
        $this->assertSame('Driver Actual', $document->fresh()->receiver_name);
        $this->assertNull($document->fresh()->driver_signed_at);
        $this->assertNotNull($document->fresh()->sealed_at);
        $document->refresh()->load('events.user');
        $this->assertSame('Fisty Savira', $document->printedApproval('PROC_DH')->user->name);
        $this->assertSame('Agus F', $document->printedApproval('HRD')->user->name);
        $deliveryPrint = $this->actingAs($owner)->get(route('documents.print', [$document, 'template' => 'delivery']));
        $deliveryPrint->assertOk()->assertSee('Fisty Savira')->assertSee('Agus F')
            ->assertSee('Driver Actual')->assertSee('paper-approval-check', false);
        $this->assertGreaterThanOrEqual(5, substr_count($deliveryPrint->getContent(), 'paper-approval-check'));

        $smbPrint = $this->get(route('documents.print', [$document, 'template' => 'smb']));
        $smbPrint->assertOk()->assertSee('Driver Actual')->assertSee($owner->name);
        $this->assertGreaterThanOrEqual(2, substr_count($smbPrint->getContent(), 'paper-approval-check'));

        $releasePrint = $this->get(route('documents.print', [$document, 'template' => 'release']));
        $releasePrint->assertOk()
            ->assertSee('Jakarta')->assertSee($document->number)->assertSee('Fisty Savira')
            ->assertSee($owner->name)->assertSee('Buyer')->assertSee('Driver Actual')
            ->assertDontSee('................................');
    }
}
