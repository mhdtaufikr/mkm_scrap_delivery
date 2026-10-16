<?php

namespace Tests\Feature;

use App\Models\DeliveryDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScrapApplicationTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'PPC', array $attributes = []): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true, ...$attributes]);
    }

    private function data(array $extra = []): array
    {
        return ['type' => 'SCRAP', 'document_date' => '2026-10-16', 'driver_name' => 'Driver Buyer', 'company' => 'BMJ', 'destination' => 'Jakarta', 'vehicle' => 'Truck', 'plate_number' => 'B 1234 MKM', 'goods_description' => 'Scrap besi', ...$extra];
    }

    private function document(User $owner, string $status = 'DRAFT', array $extra = []): DeliveryDocument
    {
        static $counter = 0;

        return DeliveryDocument::create([...$this->data(), 'number' => 'SMB-TEST-'.++$counter, 'created_by' => $owner->id, 'status' => $status, ...$extra]);
    }

    private function action(DeliveryDocument $document, string $action, array $extra = [])
    {
        return $this->post(route('documents.transition', $document), ['action' => $action, 'confirmed' => 1, ...$extra]);
    }

    public function test_guest_can_only_open_login_and_cannot_register(): void
    {
        $this->get('/')->assertRedirect(route('auth.azure'));
        $this->get('/MKM@dm1n')->assertOk()->assertSee('Sign in')->assertSee('assets/img/mms.png');
        foreach (['/home', '/documents', '/users', '/notifications'] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
        $this->get('/register')->assertNotFound();
    }

    public function test_login_logout_and_invalid_credentials(): void
    {
        $user = $this->account('PPC', ['username' => 'ppc']);
        $this->post('/MKM@dm1n', ['login' => 'ppc', 'password' => 'wrong'])->assertSessionHasErrors('login');
        $this->assertGuest();
        $this->post('/MKM@dm1n', ['login' => 'ppc', 'password' => 'password'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_login_is_throttled(): void
    {
        $this->freezeTime();
        for ($i = 0; $i < 6; $i++) {
            $response = $this->post('/MKM@dm1n', ['login' => 'throttle-user', 'password' => 'wrong']);
        }
        $response->assertSessionHasErrors(['login' => 'Too many attempts. Try again in 60 seconds.']);
    }

    public function test_inactive_account_cannot_login_or_keep_session(): void
    {
        $user = $this->account('PPC', ['is_active' => false]);
        $this->post('/MKM@dm1n', ['login' => $user->email, 'password' => 'password'])->assertSessionHasErrors('login');
        $this->actingAs($user)->get('/home')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_first_login_requires_password_change(): void
    {
        $user = $this->account('ADMIN', ['must_change_password' => true]);
        $this->actingAs($user)->get('/home')->assertRedirect(route('password.edit'));
        $this->get('/password')->assertOk();
        $this->put('/password', ['current_password' => 'password', 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123'])->assertRedirect(route('dashboard'));
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('NewPassword123', $user->fresh()->password));
    }

    public function test_only_admin_can_create_and_manage_users_without_self_lockout(): void
    {
        $ppc = $this->account();
        $this->actingAs($ppc)->get('/users')->assertForbidden();
        $this->post('/users', [])->assertForbidden();
        $admin = $this->account('ADMIN');
        $this->actingAs($admin)->get('/users')->assertOk();
        $this->post('/users', ['name' => 'HRD Staff', 'username' => 'hrd', 'email' => 'hrd@example.com', 'role' => 'HRD', 'password' => 'Temporary123', 'password_confirmation' => 'Temporary123'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['username' => 'hrd', 'role' => 'HRD', 'must_change_password' => true]);
        $this->patch(route('users.update', $admin), ['role' => 'VIEWER', 'is_active' => 0])->assertStatus(422);
        $this->patch(route('users.update', $ppc), ['role' => 'VIEWER', 'is_active' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($ppc->fresh()->is_active);
    }

    public function test_authenticated_pages_render_for_every_role(): void
    {
        foreach (array_keys(User::ROLES) as $role) {
            $user = $this->account($role);
            $this->actingAs($user)->get('/home')->assertOk()->assertSee('Total = Open + Close');
            $this->get('/documents')->assertOk();
            $this->get('/notifications')->assertOk();
        }
    }

    public function test_ppc_creates_unique_documents_and_notifies_security_and_procurement(): void
    {
        $ppc = $this->account();
        $security = $this->account('SECURITY');
        $staff = $this->account('PROC_STAFF');
        $this->actingAs($ppc)->post('/documents', $this->data(['status' => 'COMPLETED', 'created_by' => $security->id]))->assertSessionHasNoErrors();
        $doc = DeliveryDocument::firstOrFail();
        $this->assertSame('DRAFT', $doc->status);
        $this->assertSame($ppc->id, $doc->created_by);
        $this->assertStringStartsWith('SMB-', $doc->number);
        $this->post('/documents', $this->data())->assertSessionHasNoErrors();
        $this->assertSame(2, DeliveryDocument::distinct()->count('number'));
        $this->assertSame(2, $security->notifications()->count());
        $this->assertSame(2, $staff->notifications()->count());
        $this->get(route('documents.show', $doc))->assertOk()->assertSee($doc->number);
        $this->get(route('documents.edit', $doc))->assertOk();
    }

    public function test_draft_ownership_and_post_submission_immutability_are_enforced(): void
    {
        $owner = $this->account();
        $other = $this->account();
        $doc = $this->document($owner);
        $this->actingAs($other)->get(route('documents.edit', $doc))->assertForbidden();
        $this->put(route('documents.update', $doc), $this->data())->assertForbidden();
        $this->actingAs($this->account('ADMIN'))->post('/documents', $this->data())->assertForbidden();
        $this->actingAs($owner);
        $this->action($doc, 'submit')->assertSessionHasNoErrors();
        $this->put(route('documents.update', $doc), $this->data())->assertForbidden();
        $this->action($doc, 'submit')->assertForbidden();
    }

    public function test_scrap_workflow_requires_roles_and_signed_evidence_before_release(): void
    {
        Storage::fake('local');
        $ppc = $this->account();
        $doc = $this->document($ppc);
        $this->actingAs($ppc);
        $this->action($doc, 'submit')->assertSessionHasNoErrors();
        $this->actingAs($this->account('ADMIN'));
        $this->action($doc, 'approve')->assertForbidden();
        $this->actingAs($this->account('PROC_STAFF'));
        $this->action($doc, 'release')->assertSessionHasErrors('action');
        $this->action($doc, 'next')->assertSessionHasNoErrors();
        $this->assertSame('PROC_DH', $doc->fresh()->status);
        $this->actingAs($this->account('PROC_DH'));
        $this->action($doc, 'approve')->assertSessionHasNoErrors();
        $this->actingAs($this->account('HRD'));
        $this->action($doc, 'approve')->assertSessionHasNoErrors();
        $this->assertSame('HANDOVER', $doc->fresh()->status);
        $security = $this->account('SECURITY');
        $this->actingAs($security);
        $this->action($doc, 'release')->assertForbidden();
        $this->actingAs($ppc);
        $this->action($doc, 'handover')->assertSessionHasErrors(['receiver_name', 'evidence']);
        $this->action($doc, 'handover', ['receiver_name' => 'Penerima Buyer', 'evidence' => UploadedFile::fake()->create('signed.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
        Storage::disk('local')->assertExists($doc->fresh()->handover_path);
        $this->actingAs($security);
        $this->action($doc, 'release')->assertSessionHasNoErrors();
        $this->assertSame('COMPLETED', $doc->fresh()->status);
        $this->assertNotNull($doc->fresh()->completed_at);
        $this->assertSame(6, $doc->events()->count());
        $this->get(route('documents.evidence', $doc))->assertOk();
        $this->action($doc, 'release')->assertForbidden();
    }

    public function test_delegate_requires_reason_and_notifies_dh_and_sh(): void
    {
        $ppc = $this->account();
        $doc = $this->document($ppc, 'PROC_STAFF');
        $dh = $this->account('PROC_DH');
        $sh = $this->account('PROC_SH');
        $this->actingAs($sh);
        $this->action($doc, 'delegate', ['notes' => 'DH sedang di luar kantor'])->assertForbidden();
        $staff = $this->account('PROC_STAFF');
        $this->actingAs($staff);
        $this->action($doc, 'delegate')->assertSessionHasErrors('notes');
        $this->action($doc, 'delegate', ['notes' => 'DH sedang di luar kantor'])->assertSessionHasNoErrors();
        $this->assertSame('HRD', $doc->fresh()->status);
        $this->assertSame($staff->id, $doc->events()->first()->user_id);
        $this->assertSame(1, $dh->notifications()->count());
        $this->assertSame(1, $sh->notifications()->count());
    }

    public function test_return_invalidates_current_handover_but_preserves_historical_evidence(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('handover/old.pdf', 'old');
        $doc = $this->document($this->account(), 'SECURITY', ['handover_path' => 'handover/old.pdf', 'receiver_name' => 'Old Receiver']);
        $event = $doc->events()->create(['user_id' => $doc->created_by, 'actor_role' => 'PPC', 'action' => 'handover', 'to_status' => 'SECURITY', 'evidence_path' => 'handover/old.pdf']);
        $this->actingAs($this->account('SECURITY'));
        $this->action($doc, 'return', ['notes' => 'Plate number perlu diperbaiki'])->assertSessionHasNoErrors();
        $this->assertNull($doc->fresh()->handover_path);
        $this->assertSame('DRAFT', $doc->fresh()->status);
        $this->get(route('documents.evidence', $doc))->assertNotFound();
        $this->get(route('documents.event-evidence', [$doc, $event->id]))->assertOk();
        $other = $this->document($this->account());
        $this->get(route('documents.event-evidence', [$other, $event->id]))->assertNotFound();
    }

    public function test_disposal_requires_reference_and_cannot_enter_scrap_approval(): void
    {
        $ppc = $this->account();
        $this->actingAs($ppc)->post('/documents', $this->data(['type' => 'DISPOSAL']))->assertSessionHasErrors('workflow_reference');
        $this->post('/documents', $this->data(['type' => 'DISPOSAL', 'workflow_reference' => 'WF-SETTLED-001']))->assertSessionHasNoErrors();
        $doc = DeliveryDocument::firstOrFail();
        $this->assertStringStartsWith('SPB-', $doc->number);
        $this->action($doc, 'submit')->assertForbidden();
        $this->get(route('documents.show', $doc))->assertOk()->assertSee('not integrated');
    }

    public function test_filters_counts_queue_and_notifications_follow_the_current_user(): void
    {
        $ppc = $this->account();
        $staff = $this->account('PROC_STAFF');
        $doc = $this->document($ppc, 'PROC_STAFF', ['company' => 'BMJ']);
        $this->document($ppc, 'COMPLETED', ['company' => 'BMJ']);
        $this->document($ppc, 'DRAFT', ['company' => 'NIB']);
        $this->actingAs($staff)->get('/home?q=BMJ')->assertOk()->assertViewHas('total', 2);
        $this->get('/documents?q=BMJ')->assertViewHas('total', 2)->assertViewHas('closed', 1);
        $this->get('/documents?queue=1')->assertViewHas('total', 1);
        $this->actingAs($this->account('VIEWER'))->get('/documents?queue=1')->assertViewHas('total', 0);
        $this->actingAs($ppc);
        $draft = $this->document($ppc);
        $this->action($draft, 'submit')->assertSessionHasNoErrors();
        $notice = $staff->notifications()->firstOrFail();
        $this->post(route('notifications.read', $notice->id))->assertNotFound();
        $this->actingAs($staff)->post(route('notifications.read', $notice->id))->assertRedirect(route('documents.show', $draft));
        $this->assertNotNull($notice->fresh()->read_at);
    }

    public function test_admin_can_reset_other_accounts_but_a_user_cannot_reset_another_password(): void
    {
        $admin = $this->account('ADMIN');
        $ppc = $this->account('PPC');
        $data = ['password' => 'ResetPassword123', 'password_confirmation' => 'ResetPassword123'];
        $this->actingAs($ppc)->put(route('users.password', $admin), $data)->assertForbidden();
        $this->actingAs($admin)->put(route('users.password', $admin), $data)->assertStatus(422);
        $this->put(route('users.password', $ppc), $data)->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check($data['password'], $ppc->fresh()->password));
        $this->assertTrue($ppc->fresh()->must_change_password);
    }

    public function test_action_confirmation_and_private_file_validation_cannot_be_bypassed(): void
    {
        Storage::fake('local');
        $ppc = $this->account();
        $doc = $this->document($ppc, 'HANDOVER');
        $this->actingAs($ppc);
        $this->post(route('documents.transition', $doc), ['action' => 'handover'])->assertSessionHasErrors('confirmed');
        $this->action($doc, 'handover', ['receiver_name' => 'Receiver', 'evidence' => UploadedFile::fake()->create('script.php', 10, 'application/x-php')])->assertSessionHasErrors('evidence');
        $this->action($doc, 'handover', ['receiver_name' => 'Receiver', 'evidence' => UploadedFile::fake()->create('large.pdf', 6000, 'application/pdf')])->assertSessionHasErrors('evidence');
        $this->assertSame('HANDOVER', $doc->fresh()->status);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->post('/logout');
        $this->get(route('documents.evidence', $doc))->assertRedirect(route('login'));
    }

    public function test_bootstrap_creates_one_admin_without_overwriting_existing_credentials(): void
    {
        Storage::fake('local');
        $this->artisan('app:create-admin')->assertSuccessful();
        $this->assertDatabaseHas('users', ['username' => 'admin', 'role' => 'ADMIN', 'must_change_password' => true]);
        Storage::disk('local')->assertExists('bootstrap-admin.txt');
        $original = Storage::disk('local')->get('bootstrap-admin.txt');
        $this->artisan('app:create-admin')->assertFailed();
        $this->assertSame(1, User::count());
        $this->assertSame($original, Storage::disk('local')->get('bootstrap-admin.txt'));
    }
}
