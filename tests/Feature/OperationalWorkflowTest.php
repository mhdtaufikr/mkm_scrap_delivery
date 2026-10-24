<?php

namespace Tests\Feature;

use App\Models\DeliveryDocument;
use App\Models\User;
use App\Services\DocumentWorkflow;
use Database\Seeders\OperationalSetupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
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
        $this->actingAs($owner)->get(route('documents.print', [$document->fresh(), 'template' => 'smb']))->assertOk()
            ->assertSee('PINDAI DI POS SECURITY')->assertSee($document->fresh()->securityCode());
        $this->post(route('security.lookup'), ['security_code' => $document->fresh()->securityCode()])
            ->assertRedirect(route('security.show', $document));
        $this->get(route('security.show', $document))->assertOk()->assertSee('Persetujuan belum selesai');
        $workflow->transition($document, User::where('username', 'mkm02119')->firstOrFail(), 'approve');
        $this->assertSame('HRA_APPROVAL', $document->fresh()->status);
        $workflow->transition($document, User::where('username', 'mkm01436')->firstOrFail(), 'approve');
        $this->assertSame('DRIVER_HANDOVER', $document->fresh()->status);

        Storage::fake('local');
        $token = $document->fresh()->driverToken();
        $this->assertNotNull($token);
        $this->get(route('security.show', $document))->assertOk()
            ->assertSee('PINDAI UNTUK TANDA TANGAN')->assertSee('data:image/svg+xml;base64,', false);
        $this->get(route('driver-signature.show', ['token' => $token]))->assertOk()->assertSee($document->number)->assertSee('Nama lengkap pengemudi');
        $signature = "\x89PNG\r\n\x1a\n".str_repeat('signature', 20);
        $this->post(route('driver-signature.store', ['token' => $token]), [
            'driver_name' => 'Driver Actual', 'signature' => 'data:image/png;base64,'.base64_encode($signature), 'confirmed' => 1,
        ])->assertRedirect(route('driver-signature.show', ['token' => $token]));
        $this->assertNotNull($document->fresh()->driver_signed_at);
        $this->getJson(route('security.status', $document))->assertOk()->assertJson(['signed' => true]);
        $this->post(route('driver-signature.store', ['token' => $token]), [
            'driver_name' => 'Changed Driver', 'signature' => 'data:image/png;base64,'.base64_encode($signature), 'confirmed' => 1,
        ])->assertStatus(409);
        $document->refresh();
        $this->get(route('security.show', $document))->assertOk()->assertSee('Beri stempel digital &amp; selesaikan', false);
        $this->post(route('security.approve', $document), [
            'inspection_version' => $document->inspectionVersion(), 'security_name' => 'Security Actual',
            'goods_checked' => 1, 'vehicle_checked' => 1, 'documents_checked' => 1,
        ])->assertRedirect(route('security.show', $document));
        $this->assertSame('COMPLETED', $document->fresh()->status);
        $this->assertDatabaseHas('document_events', ['delivery_document_id' => $document->id, 'security_officer_name' => 'Security Actual', 'to_status' => 'COMPLETED']);
        $this->assertSame('Security Actual', $document->fresh()->seal_actual_name);
        $this->assertNotNull($document->fresh()->sealed_at);
        $document->refresh()->load('events.user');
        $this->assertSame('Fisty Savira', $document->printedApproval('PROC_DH')->user->name);
        $this->assertSame('Agus F', $document->printedApproval('HRD')->user->name);
        $this->actingAs($owner)->get(route('documents.print', [$document, 'template' => 'delivery']))->assertOk()
            ->assertSee('Fisty Savira')->assertSee('Agus F')->assertSee('paper-approval-check', false);
    }
}
