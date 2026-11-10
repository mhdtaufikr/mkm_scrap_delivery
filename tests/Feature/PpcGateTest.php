<?php

namespace Tests\Feature;

use App\Models\DeliveryDocument;
use App\Models\User;
use App\Notifications\DocumentUpdated;
use App\Services\DocumentWorkflow;
use Database\Seeders\OperationalSetupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PpcGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['workflow.initial_password' => 'Temporary#2026']);
        $this->seed(OperationalSetupSeeder::class);
    }

    private function createDisposal(): DeliveryDocument
    {
        $owner = User::where('username', 'mkm01868')->firstOrFail();
        $this->actingAs($owner)->post(route('documents.store'), [
            'type' => 'DISPOSAL', 'document_date' => '2026-11-10',
            'company' => 'Buyer Disposal', 'destination' => 'Jakarta', 'vehicle' => 'Truck', 'plate_number' => 'B 7788 MKM',
            'items' => [['name' => 'Material Disposal', 'quantity' => 1, 'unit' => 'kg']],
            'form_details' => ['net_weight' => 100], 'submission_intent' => 'submit',
        ])->assertRedirect()->assertSessionHasNoErrors();

        return DeliveryDocument::latest('id')->firstOrFail();
    }

    public function test_disposal_stays_draft_until_ppc_gate_records_gross_weight(): void
    {
        $document = $this->createDisposal();
        $code = $document->ppcCode();

        $this->assertSame('DRAFT', $document->status);
        $this->assertNotNull($code);
        $this->assertSame(6, strlen($code));
        $this->assertNull($document->securityCode());
        $this->assertArrayNotHasKey('gross_weight', $document->form_details);

        $this->actingAs($document->creator)->post(route('documents.transition', $document), [
            'action' => 'submit', 'confirmed' => 1,
        ])->assertSessionHasErrors('action');
        $this->assertSame('DRAFT', $document->fresh()->status);

        $this->get(route('ppc.index'))->assertOk()->assertSee('Input Berat Kotor Disposal')->assertSee('Kode PPC');
        $this->post(route('ppc.lookup'), ['ppc_code' => 'WRONG1'])->assertSessionHasErrors('ppc_code');
        $this->post(route('ppc.lookup'), ['ppc_code' => strtolower($code)])->assertRedirect(route('ppc.show', $document));
        $this->get(route('ppc.show', $document))->assertOk()
            ->assertSee($document->number)->assertSee('100 kg')->assertSee('Berat kotor (kg) *')->assertSee('Simpan &amp; kirim ke Fisty', false);

        $this->post(route('ppc.weigh', $document), [
            'inspection_version' => $document->inspectionVersion(), 'gross_weight' => 99,
        ])->assertSessionHasErrors('gross_weight');
        $this->assertSame('DRAFT', $document->fresh()->status);

        $this->post(route('ppc.weigh', $document), [
            'inspection_version' => $document->fresh()->inspectionVersion(), 'gross_weight' => 125.5,
        ])->assertRedirect(route('ppc.show', $document))->assertSessionHasNoErrors();

        $document->refresh();
        $fisty = User::where('username', 'mkm02119')->firstOrFail();
        $this->assertSame('SALES_APPROVAL', $document->status);
        $this->assertSame(125.5, (float) $document->form_details['gross_weight']);
        $this->assertNotNull($document->ppc_code_used_at);
        $this->assertNotNull($document->securityCode());
        $this->assertDatabaseHas('document_events', ['delivery_document_id' => $document->id, 'action' => 'submit', 'from_status' => 'DRAFT', 'to_status' => 'SALES_APPROVAL']);
        Notification::assertSentTo($fisty, DocumentUpdated::class);
        $this->get(route('ppc.show', $document))->assertOk()->assertSee('Penimbangan PPC selesai')->assertSee('Dokumen sudah masuk proses approval Fisty');
        $this->withSession([])->post(route('ppc.lookup'), ['ppc_code' => $code])->assertSessionHasErrors('ppc_code');
    }

    public function test_ppc_gate_rejects_other_documents_and_requires_the_exact_code(): void
    {
        $document = $this->createDisposal();
        $other = $this->createDisposal();

        $this->post(route('ppc.lookup'), ['ppc_code' => $document->ppcCode()])->assertRedirect(route('ppc.show', $document));
        $this->get(route('ppc.show', $other))->assertForbidden();
        $this->post(route('ppc.weigh', $other), ['inspection_version' => $other->inspectionVersion(), 'gross_weight' => 110])->assertForbidden();
    }

    public function test_returned_disposal_gets_a_new_ppc_code_and_must_be_weighed_again(): void
    {
        $document = $this->createDisposal();
        $oldCode = $document->ppcCode();
        $this->post(route('ppc.lookup'), ['ppc_code' => $oldCode]);
        $this->post(route('ppc.weigh', $document), [
            'inspection_version' => $document->inspectionVersion(), 'gross_weight' => 125,
        ])->assertSessionHasNoErrors();

        $document->refresh();
        app(DocumentWorkflow::class)->transition(
            $document,
            User::where('username', 'mkm02119')->firstOrFail(),
            'return',
            'Berat perlu diperiksa dan ditimbang ulang.'
        );

        $document->refresh();
        $this->assertSame('DRAFT', $document->status);
        $this->assertArrayNotHasKey('gross_weight', $document->form_details);
        $this->assertNotSame($oldCode, $document->ppcCode());
        $this->assertNull($document->ppc_code_used_at);
        $this->withSession([])->post(route('ppc.lookup'), ['ppc_code' => $oldCode])->assertSessionHasErrors('ppc_code');
        $this->post(route('ppc.lookup'), ['ppc_code' => $document->ppcCode()])->assertRedirect(route('ppc.show', $document));
    }
}
