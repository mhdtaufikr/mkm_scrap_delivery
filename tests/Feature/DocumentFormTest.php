<?php

namespace Tests\Feature;

use App\Models\DeliveryDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentFormTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['type' => 'SCRAP', 'document_date' => '2026-10-16', 'driver_name' => 'Driver Test', 'company' => 'Buyer Test', 'destination' => 'Jakarta', 'vehicle' => 'Pickup', 'plate_number' => 'B 1234 XX', 'items' => [['code' => 'EX-CKD', 'name' => 'Wood EX CKD', 'quantity' => 1.5, 'unit' => 'ton', 'pallet' => 2, 'posts' => 0, 'remarks' => 'Dry']], 'form_details' => ['identity_number' => '12345', 'lot' => 'LOT-10', 'department' => 'PPC', 'loader_name' => 'Loader Test', 'attachments' => 'none', 'sales_reference' => 'SALE-001', 'sale_date' => '2026-10-17', 'location' => 'MKM 2']];
    }

    public function test_form_saves_structured_items_and_renders_all_three_templates(): void
    {
        $u = User::factory()->create(['role' => 'PPC']);
        $this->actingAs($u)->get('/documents/create')->assertOk()
            ->assertSee('Quick guide')->assertSee('Trip details')->assertSee('Save draft')
            ->assertSee('SURAT MUAT BARANG')->assertSee('data-goods-rows', false)
            ->assertSee('paper-signature paper-attachment', false)->assertSee('ATTACHMENTS');
        $this->post('/documents', $this->payload())->assertRedirect()->assertSessionHasNoErrors();
        $d = DeliveryDocument::firstOrFail();
        $this->assertSame('EX-CKD', $d->items[0]['code']);
        $this->assertSame('12345', $d->form_details['identity_number']);
        $this->assertStringContainsString('1.5 ton', $d->goods_description);
        $this->get(route('documents.show', $d))->assertOk()->assertSee('Wood EX CKD');
        $this->get(route('documents.edit', $d))->assertOk()->assertSee('SALE-001');
        foreach (['smb' => 'SURAT MUAT BARANG', 'delivery' => 'SURAT JALAN NON PRODUKSI', 'release' => 'Pengeluaran Barang'] as $template => $title) {
            $this->get(route('documents.print', [$d, 'template' => $template]))->assertOk()->assertSee($title)->assertSee('Wood EX CKD')->assertSee('DRAFT - NOT FOR GOODS RELEASE');
        }
        $this->get(route('documents.print', [$d, 'template' => 'invalid']))->assertSessionHasErrors('template');
    }

    public function test_disposal_accepts_its_own_fields_without_scrap_carrier_fields(): void
    {
        $u = User::factory()->create(['role' => 'PPC']);
        $this->actingAs($u)->post('/documents', [
            'type' => 'DISPOSAL', 'document_date' => '2026-10-16',
            'release_date' => '2026-10-17', 'release_time' => '09:30',
            'company' => 'Disposal Buyer', 'goods_description' => 'Forklift battery', 'workflow_reference' => 'WF-00123',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $d = DeliveryDocument::firstOrFail();
        $this->assertSame('DISPOSAL', $d->type);
        $this->assertStringStartsWith('SPB-', $d->number);
        $this->assertSame('', $d->driver_name);
        $this->assertSame('2026-10-17 09:30', $d->departure_at->format('Y-m-d H:i'));
        $this->assertSame('Forklift battery', $d->goods_description);
        $this->get(route('documents.edit', $d))->assertOk()
            ->assertSee('SURAT PENGELUARAN BARANG')->assertSee('Goods Release - Disposal')
            ->assertSee('paper-disposal-goods', false)->assertSee('DRAFT ONLY')->assertSee('WF-00123');
        $this->post('/documents', ['type' => 'SCRAP', 'document_date' => '2026-10-16', 'company' => 'Buyer', 'goods_description' => 'Goods'])
            ->assertSessionHasErrors(['driver_name', 'vehicle', 'plate_number', 'destination']);
    }

    public function test_disposal_requires_workflow_and_valid_complete_release_schedule(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'PPC']));
        $data = ['type' => 'DISPOSAL', 'document_date' => '2026-10-16', 'company' => 'Buyer', 'goods_description' => 'Goods'];
        $this->post('/documents', $data)->assertSessionHasErrors('workflow_reference');
        $data['workflow_reference'] = 'WF-1';
        $this->post('/documents', [...$data, 'release_date' => '2026-10-17'])->assertSessionHasErrors('release_time');
        $this->post('/documents', [...$data, 'release_date' => '2026-10-15', 'release_time' => '09:30'])->assertSessionHasErrors('departure_at');
        $this->assertDatabaseCount('delivery_documents', 0);
    }

    public function test_invalid_rows_are_rejected_and_saved_items_cannot_change_after_submission(): void
    {
        $u = User::factory()->create(['role' => 'PPC']);
        $this->actingAs($u);
        $data = $this->payload();
        $data['items'][0]['quantity'] = -1;
        $this->post('/documents', $data)->assertSessionHasErrors('items.0.quantity');
        $this->assertDatabaseCount('delivery_documents', 0);
        $data = $this->payload();
        $data['form_details']['untrusted'] = 'value';
        $this->post('/documents', $data)->assertSessionHasErrors('form_details');
        $this->post('/documents', $this->payload());
        $d = DeliveryDocument::firstOrFail();
        $d->update(['status' => 'PROC_STAFF']);
        $this->put(route('documents.update', $d), $this->payload())->assertForbidden();
        $this->assertSame('Wood EX CKD', $d->fresh()->items[0]['name']);
    }

    public function test_legacy_documents_remain_printable_and_print_requires_authenticated_approved_access(): void
    {
        $u = User::factory()->create(['role' => 'PPC']);
        $data = $this->payload();
        unset($data['items'],$data['form_details']);
        $data['goods_description'] = 'Legacy goods';
        $d = DeliveryDocument::create([...$data, 'number' => 'SMB-LEGACY', 'created_by' => $u->id, 'status' => 'DRAFT']);
        $this->get(route('documents.print', [$d, 'template' => 'smb']))->assertRedirect(route('login'));
        $pending = User::factory()->create(['access_status' => 'pending']);
        $this->actingAs($pending)->get(route('documents.print', [$d, 'template' => 'smb']))->assertRedirect(route('access.show'));
        $this->actingAs($u)->get(route('documents.print', [$d, 'template' => 'delivery']))->assertOk()->assertSee('Legacy goods');
    }
}
