<?php

namespace Tests\Feature;

use App\Models\DeliveryDocument;
use App\Models\User;
use App\Notifications\DocumentUpdated;
use App\Notifications\WorkflowUpdateMail;
use App\Services\DocumentWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ApprovalOutputTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, array $extra = []): User
    {
        return User::factory()->create(['role' => $role, ...$extra]);
    }

    private function document(User $owner): DeliveryDocument
    {
        return DeliveryDocument::create(['number' => 'SMB-OUTPUT', 'type' => 'SCRAP', 'status' => 'DRAFT', 'document_date' => today(), 'driver_name' => 'Carrier', 'company' => 'Buyer', 'destination' => 'Jakarta', 'vehicle' => 'Truck', 'plate_number' => 'B 123', 'goods_description' => 'Scrap', 'created_by' => $owner->id]);
    }

    private function act(DeliveryDocument $doc, User $user, string $action, ?string $notes = null): void
    {
        app(DocumentWorkflow::class)->transition($doc, $user, $action, $notes);
        $doc->refresh();
    }

    public function test_delegated_approval_prints_actual_staff_name_date_and_reason_in_both_documents(): void
    {
        Notification::fake();
        $owner = $this->user('PPC');
        $staff = $this->user('PROC_STAFF', ['name' => 'Delegating Staff']);
        $doc = $this->document($owner);
        $this->act($doc, $owner, 'submit');
        $this->act($doc, $staff, 'delegate', 'Department head is away from the office.');
        $this->assertSame('HRD', $doc->status);
        $this->assertSame('delegate', $doc->printedApproval('PROC_DH')->action);
        foreach (['delivery', 'release'] as $template) {
            $this->actingAs($owner)->get(route('documents.print', [$doc, 'template' => $template]))->assertOk()->assertSee('Delegating Staff')->assertSee('Approved by Procurement Staff (Delegate)')->assertSee('Department head is away from the office.')->assertSee($doc->events()->where('action', 'delegate')->first()->created_at->format('d M Y H:i'));
        }
    }

    public function test_return_discards_prior_printed_approvals_and_new_normal_cycle_uses_department_head(): void
    {
        Notification::fake();
        $owner = $this->user('PPC');
        $staff = $this->user('PROC_STAFF', ['name' => 'Previous Delegating Staff']);
        $dh = $this->user('PROC_DH', ['name' => 'Current Department Head']);
        $hrd = $this->user('HRD');
        $doc = $this->document($owner);
        $this->act($doc, $owner, 'submit');
        $this->act($doc, $staff, 'delegate', 'Head is out of the office.');
        $this->act($doc, $hrd, 'return', 'Please correct the goods information.');
        $this->assertNull($doc->printedApproval('PROC_DH'));
        $this->actingAs($owner)->get(route('documents.print', [$doc, 'template' => 'release']))->assertDontSee('Previous Delegating Staff')->assertDontSee('Head is out of the office.');
        $this->act($doc, $owner, 'submit');
        $this->act($doc, $staff, 'next');
        $this->assertNull($doc->printedApproval('PROC_DH'));
        $this->act($doc, $dh, 'approve');
        $this->assertSame($dh->id, $doc->printedApproval('PROC_DH')->user_id);
        $this->get(route('documents.print', [$doc, 'template' => 'delivery']))->assertOk()->assertSee('Current Department Head')->assertDontSee('Approved by Procurement Staff (Delegate)');
    }

    public function test_delegate_queues_mail_for_dh_sh_and_next_approver_but_excludes_unapproved_and_demo_accounts(): void
    {
        Notification::fake();
        $owner = $this->user('PPC');
        $staff = $this->user('PROC_STAFF');
        $dh = $this->user('PROC_DH');
        $sh = $this->user('PROC_SH');
        $hrd = $this->user('HRD');
        $pending = $this->user('PROC_SH', ['access_status' => 'pending']);
        $disabled = $this->user('PROC_DH', ['is_active' => false]);
        $demo = $this->user('PROC_SH', ['email' => 'demo@scrap.local']);
        $doc = $this->document($owner);
        $this->act($doc, $owner, 'submit');
        $this->act($doc, $staff, 'delegate', 'Head is out of the office.');
        foreach ([$dh, $sh, $hrd] as $recipient) {
            Notification::assertSentTo($recipient, DocumentUpdated::class);
            Notification::assertSentTo($recipient, WorkflowUpdateMail::class, fn ($n) => $n->reason === 'Head is out of the office.' && $n->queue === 'workflow-mail' && $n->connection === 'database' && $n->afterCommit === true);
        }
        Notification::assertNotSentTo([$pending, $disabled], DocumentUpdated::class);
        Notification::assertNotSentTo([$pending, $disabled, $demo], WorkflowUpdateMail::class);
        $mail = (new WorkflowUpdateMail($doc->id, $doc->number, 'Delegated approval', 'HRD Approval', 'Head is out of the office.'))->toMail($dh);
        $this->assertSame(route('documents.show', $doc), $mail->actionUrl);
        $this->assertContains('Reason / notes: Head is out of the office.', $mail->introLines);
    }
}
