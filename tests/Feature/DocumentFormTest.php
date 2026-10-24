<?php

namespace Tests\Feature;

use App\Models\DeliveryDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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
            ->assertSee('Panduan singkat')->assertSee('Data perjalanan')->assertSee('Simpan sebagai draf')->assertSee('Simpan &amp; kirim untuk persetujuan', false)
            ->assertSee('SURAT MUAT BARANG')->assertSee('data-goods-rows', false)
            ->assertSee('Tempel dari Excel')->assertSee('data-goods-paste', false)
            ->assertSee('paper-signature paper-attachment', false)->assertSee('Ada dokumen lampiran fisik')
            ->assertSee('SURAT PENGELUARAN BARANG')->assertSee('Berat bersih (kg)');
        $this->post('/documents', $this->payload())->assertRedirect()->assertSessionHasNoErrors();
        $d = DeliveryDocument::firstOrFail();
        $this->assertSame('EX-CKD', $d->items[0]['code']);
        $this->assertSame('12345', $d->form_details['identity_number']);
        $this->assertStringContainsString('1.5 ton', $d->goods_description);
        $this->get(route('documents.show', $d))->assertOk()->assertSee('Wood EX CKD');
        $this->get(route('documents.edit', $d))->assertOk()->assertSee('SALE-001');
        foreach (['smb' => 'SURAT MUAT BARANG', 'delivery' => 'SURAT JALAN NON PRODUKSI', 'release' => 'Pengeluaran Barang'] as $template => $title) {
            $this->get(route('documents.print', [$d, 'template' => $template]))->assertOk()->assertSee($title)->assertSee('Wood EX CKD')->assertSee('DRAF - BELUM SAH UNTUK PENGELUARAN BARANG')->assertDontSee("@include('pwa.head')", false);
        }
        Storage::fake('local');
        Storage::disk('local')->put('driver-signatures/test.png', "\x89PNG\r\n\x1a\n".str_repeat('signature', 20));
        $approver = User::factory()->create(['role' => 'SECURITY']);
        $d->update(['status' => 'SECURITY', 'driver_signed_name' => 'Driver Signed', 'driver_signature_path' => 'driver-signatures/test.png', 'driver_signed_at' => now()]);
        $d->events()->create(['user_id' => $approver->id, 'actor_role' => 'SECURITY', 'action' => 'release', 'from_status' => 'SECURITY', 'to_status' => 'COMPLETED']);
        $this->get(route('documents.print', [$d, 'template' => 'smb']))->assertOk()
            ->assertSee('paper-approval-check', false)->assertSee('✓')->assertSee('Driver Signed')
            ->assertSee('data:image/png;base64,', false);
        $this->get(route('documents.print', [$d, 'template' => 'invalid']))->assertSessionHasErrors('template');
    }

    public function test_disposal_uses_automatic_delivery_number_and_weight_difference(): void
    {
        $u = User::factory()->create(['role' => 'PPC']);
        $this->actingAs($u)->post('/documents', [
            'type' => 'DISPOSAL', 'document_date' => '2026-10-16',
            'release_date' => '2026-10-17', 'release_time' => '09:30',
            'driver_name' => 'Driver Disposal', 'company' => 'Disposal Buyer', 'destination' => 'Jakarta', 'vehicle' => 'Truck', 'plate_number' => 'B 1234 DP', 'workflow_reference' => 'WF-00123',
            'items' => [['name' => 'Forklift battery', 'quantity' => 1, 'unit' => 'kg']],
            'form_details' => ['net_weight' => 120, 'gross_weight' => 135],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $d = DeliveryDocument::firstOrFail();
        $this->assertSame('MKM/JKT/0001/X/2026', $d->number);
        $this->assertSame(120, $d->form_details['net_weight']);
        $this->assertSame(135, $d->form_details['gross_weight']);
        $this->get(route('documents.print', [$d, 'template' => 'delivery']))->assertOk()
            ->assertSee('MKM/JKT/0001/X/2026')->assertSee('BERAT BERSIH (KG)')->assertSee('15');

        $legacy = DeliveryDocument::create([
            'type' => 'DISPOSAL', 'number' => 'SPB-LEGACY', 'status' => 'DRAFT', 'created_by' => $u->id,
            'document_date' => '2026-10-16', 'departure_at' => '2026-10-17 09:30', 'driver_name' => '',
            'company' => 'Disposal Buyer', 'destination' => '', 'vehicle' => '', 'plate_number' => '',
            'goods_description' => 'Forklift battery', 'workflow_reference' => 'WF-00123',
        ]);
        $this->get(route('documents.edit', $legacy))->assertOk()
            ->assertSee('SURAT PENGELUARAN BARANG')->assertSee('Goods Release - Disposal')
            ->assertSee('Berat bersih (kg)')->assertSee('DRAFT ONLY')->assertSee('WF-00123');
        $this->post('/documents', ['type' => 'SCRAP', 'document_date' => '2026-10-16', 'company' => 'Buyer', 'goods_description' => 'Goods'])
            ->assertSessionHasErrors(['driver_name', 'vehicle', 'plate_number', 'destination']);
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

    public function test_excel_style_pallet_and_post_codes_are_accepted(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'PPC']));
        $data = $this->payload();
        $data['items'][0]['quantity'] = 1250;
        $data['items'][0]['pallet'] = 'PLT-001';
        $data['items'][0]['posts'] = 'P300';
        $this->post('/documents', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('delivery_documents', 1);
        $item = DeliveryDocument::firstOrFail()->items[0];
        $this->assertSame('PLT-001', $item['pallet']);
        $this->assertSame('P300', $item['posts']);
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
