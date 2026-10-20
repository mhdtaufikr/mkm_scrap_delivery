<?php

namespace Tests\Feature;

use App\Models\DeliveryDocument;
use App\Models\User;
use App\Services\DocumentWorkflow;
use Database\Seeders\OperationalSetupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OperationalWorkflowTest extends TestCase
{
    use RefreshDatabase;

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
            $this->assertDatabaseHas('users', ['username' => $username, 'role' => $role, 'local_login_enabled' => true, 'must_change_password' => true]);
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
        $workflow->transition($document, User::where('username', 'mkm02119')->firstOrFail(), 'approve');
        $this->assertSame('HRA_APPROVAL', $document->fresh()->status);
        $workflow->transition($document, User::where('username', 'mkm01436')->firstOrFail(), 'approve');
        $this->assertSame('WAREHOUSE_PICKUP', $document->fresh()->status);
        $workflow->transition($document, User::where('username', 'mkm01478')->firstOrFail(), 'approve', null, [], ['actual_actor_name' => 'Warehouse Officer']);
        $this->assertSame('DRIVER_HANDOVER', $document->fresh()->status);

        Storage::fake('local');
        Storage::disk('local')->put('handover/operational.pdf', 'signed');
        $workflow->transition($document, $owner, 'handover', null, ['handover_path' => 'handover/operational.pdf', 'receiver_name' => 'Driver Actual']);
        $document->refresh();
        $this->assertSame('SECURITY', $document->status);
        $this->assertNotNull($document->securityCode());

        $this->post(route('security.lookup'), ['security_code' => $document->securityCode()])->assertRedirect(route('security.show', $document));
        $this->post(route('security.approve', $document), [
            'inspection_version' => $document->inspectionVersion(), 'security_name' => 'Security Actual',
            'goods_checked' => 1, 'vehicle_checked' => 1, 'documents_checked' => 1,
        ])->assertRedirect(route('security.show', $document));
        $this->assertSame('COMPLETED', $document->fresh()->status);
        $this->assertDatabaseHas('document_events', ['delivery_document_id' => $document->id, 'actual_actor_name' => 'Warehouse Officer', 'to_status' => 'DRIVER_HANDOVER']);
        $this->assertDatabaseHas('document_events', ['delivery_document_id' => $document->id, 'security_officer_name' => 'Security Actual', 'to_status' => 'COMPLETED']);
    }
}
