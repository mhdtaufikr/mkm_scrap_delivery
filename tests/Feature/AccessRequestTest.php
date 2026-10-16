<?php

namespace Tests\Feature;

use App\Models\AccessRequest;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AccessRequestMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AccessRequestTest extends TestCase
{
    use RefreshDatabase;

    private function newcomer(): User
    {
        return User::factory()->create(['role' => 'VIEWER', 'access_status' => 'onboarding', 'local_login_enabled' => false]);
    }

    private function payload(): array
    {
        return ['requested_role' => 'PPC', 'responsibility' => 'Receiving officer', 'reason' => 'Receive and verify scrap delivery documents.'];
    }

    public function test_pending_user_requests_role_without_receiving_it_and_admin_is_notified(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $u = $this->newcomer();
        $this->actingAs($u)->withSession(['auth_method' => 'sso'])->get('/access')->assertOk()->assertSee('Tell us what access you need');
        foreach (['/home', '/users', '/documents', '/access-requests'] as $path) {
            $this->get($path)->assertRedirect(route('access.show'));
        }
        $this->post('/access', $this->payload())->assertRedirect(route('access.show'))->assertSessionHasNoErrors();
        $this->assertSame('VIEWER', $u->fresh()->role);
        $this->assertSame('pending', $u->fresh()->access_status);
        $this->post('/access', $this->payload())->assertStatus(409);
        $this->assertDatabaseCount('access_requests', 1);
        Notification::assertSentTo($admin, AccessRequestMail::class, fn ($n) => $n->forAdmin);
        $this->actingAs($u->fresh())->get('/access')->assertSee('Waiting for administrator approval');
    }

    public function test_approval_enables_only_requested_role_and_emails_user_once(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $u = $this->newcomer();
        $this->actingAs($u)->withSession(['auth_method' => 'sso'])->post('/access', $this->payload());
        $r = AccessRequest::firstOrFail();
        $this->actingAs($admin)->get('/access-requests')->assertOk()->assertSee('Receiving officer');
        $this->get('/access-requests/'.$r->id.'/decision')->assertStatus(405);
        $this->post(route('access-requests.decide', $r), ['decision' => 'approved'])->assertRedirect();
        $this->assertSame('PPC', $u->fresh()->role);
        $this->assertSame('approved', $u->fresh()->access_status);
        $this->assertDatabaseHas('access_requests', ['id' => $r->id, 'reviewed_by' => $admin->id, 'status' => 'approved']);
        Notification::assertSentToTimes($u, AccessRequestMail::class, 1);
        $this->post(route('access-requests.decide', $r), ['decision' => 'rejected', 'review_note' => 'Changed mind'])->assertStatus(409);
        $this->actingAs($u->fresh())->get('/home')->assertOk();
    }

    public function test_rejection_requires_reason_and_allows_a_new_request_with_history(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $u = $this->newcomer();
        $this->actingAs($u)->withSession(['auth_method' => 'sso'])->post('/access', $this->payload());
        $r = AccessRequest::firstOrFail();
        $this->actingAs($admin)->post(route('access-requests.decide', $r), ['decision' => 'rejected'])->assertSessionHasErrors('review_note');
        $this->post(route('access-requests.decide', $r), ['decision' => 'rejected', 'review_note' => 'Please clarify your department.'])->assertRedirect();
        $this->actingAs($u->fresh())->get('/home')->assertRedirect(route('access.show'));
        $this->get('/access')->assertSee('Please clarify your department.');
        $this->post('/access', $this->payload())->assertRedirect();
        $this->assertDatabaseCount('access_requests', 2);
    }

    public function test_non_admin_cannot_review_and_inactive_role_cannot_be_approved(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $u = $this->newcomer();
        $this->actingAs($u)->withSession(['auth_method' => 'sso'])->post('/access', $this->payload());
        $r = AccessRequest::firstOrFail();
        $viewer = User::factory()->create(['role' => 'VIEWER']);
        $this->actingAs($viewer)->post(route('access-requests.decide', $r), ['decision' => 'approved'])->assertForbidden();
        Role::where('code', 'PPC')->update(['is_active' => false]);
        $this->actingAs($admin)->post(route('access-requests.decide', $r), ['decision' => 'approved'])->assertStatus(422);
        $this->patch(route('users.update', $u), ['role' => 'ADMIN', 'is_active' => true])->assertStatus(422);
        $this->assertSame('pending', $u->fresh()->access_status);
    }

    public function test_mail_contains_review_details_and_decision_without_unprotected_approval_link(): void
    {
        Notification::fake();
        User::factory()->create(['role' => 'ADMIN']);
        $u = $this->newcomer();
        $this->actingAs($u)->withSession(['auth_method' => 'sso'])->post('/access', $this->payload());
        $r = AccessRequest::firstOrFail();
        $mail = (new AccessRequestMail($r, true))->toMail($u);
        $this->assertStringContainsString('/access-requests?request=', $mail->actionUrl);
        $this->assertContains('Responsibilities: Receiving officer', $mail->introLines);
        $r->update(['status' => 'approved']);
        $mail = (new AccessRequestMail($r))->toMail($u);
        $this->assertStringContainsString('approved', $mail->subject);
    }
}
