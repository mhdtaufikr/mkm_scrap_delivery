<?php

namespace Tests\Feature;

use App\Models\ApprovalFlow;
use App\Models\DeliveryDocument;
use App\Models\Role;
use App\Models\User;
use App\Services\ApprovalConfiguration;
use Database\Seeders\LocalAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DynamicApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'ADMIN'): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function document(User $owner): DeliveryDocument
    {
        static $seq = 0;

        return DeliveryDocument::create(['number' => 'DYNAMIC-'.++$seq, 'created_by' => $owner->id, 'type' => 'SCRAP', 'status' => 'DRAFT', 'document_date' => today(), 'driver_name' => 'Driver', 'company' => 'BMJ', 'destination' => 'Jakarta', 'vehicle' => 'Truck', 'plate_number' => 'B 123 MKM', 'goods_description' => 'Scrap']);
    }

    private function act(User $user, DeliveryDocument $document, string $action)
    {
        return $this->actingAs($user)->post(route('documents.transition', $document), ['action' => $action, 'confirmed' => 1]);
    }

    public function test_seeders_preserve_roles_published_config_and_existing_passwords(): void
    {
        $flow = ApprovalFlow::firstOrFail();
        $flow->steps()->where('code', 'HRD')->update(['name' => 'HRD customized']);
        Role::where('code', 'PPC')->update(['name' => 'Custom PPC']);
        $this->seed();
        $this->assertSame(1, ApprovalFlow::count());
        $this->assertSame(1, $flow->fresh()->version);
        $this->assertSame('HRD customized', $flow->steps()->where('code', 'HRD')->first()->name);
        $this->assertSame('Custom PPC', Role::where('code', 'PPC')->first()->name);
        $this->app->detectEnvironment(fn () => 'local');
        config(['demo.enabled' => true, 'demo.password' => 'LocalPassword123']);
        $this->seed(LocalAccountSeeder::class);
        $user = User::where('username', 'admin.scrap')->firstOrFail();
        $user->update(['password' => 'ChangedPassword123', 'must_change_password' => false]);
        $this->seed(LocalAccountSeeder::class);
        $this->assertSame(8, User::count());
        $this->assertTrue(Hash::check('ChangedPassword123', $user->fresh()->password));
        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_demo_accounts_are_never_seeded_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['demo.enabled' => true, 'demo.password' => 'LocalPassword123']);
        app(LocalAccountSeeder::class)->run();
        $this->assertSame(0, User::count());
    }

    public function test_master_pages_and_permissions_are_server_side(): void
    {
        $viewer = $this->user('VIEWER');
        foreach (['roles.index', 'approvals.index'] as $route) {
            $this->actingAs($viewer)->get(route($route))->assertForbidden();
        }
        $this->post(route('approvals.store'), ['name' => 'No permission'])->assertForbidden();
        $admin = $this->user();
        $this->actingAs($admin)->get(route('roles.index'))->assertOk();
        $this->get(route('approvals.index'))->assertOk();
        $this->get(route('approvals.edit', ApprovalFlow::first()))->assertOk()->assertSee('Publish version 2');
        $this->post(route('roles.store'), ['code' => 'PPC_EXTRA', 'name' => 'PPC Additional', 'permissions' => ['documents.create'], 'is_active' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $custom = $this->user('PPC_EXTRA');
        $this->actingAs($custom)->get(route('documents.create'))->assertOk();
        $this->get(route('roles.index'))->assertForbidden();
        $this->actingAs($admin)->patch(route('roles.update', Role::where('code', 'ADMIN')->first()), ['name' => 'Administrator', 'permissions' => [], 'is_active' => 1])->assertStatus(422);
    }

    public function test_unpublished_changes_do_not_change_actions_or_current_published_definition(): void
    {
        $flow = ApprovalFlow::first();
        $flow->steps()->where('code', 'PROC_STAFF')->update(['next_code' => 'MISSING', 'role_code' => 'VIEWER']);
        $doc = $this->document($this->user('PPC'));
        $this->act($doc->creator, $doc, 'submit')->assertRedirect()->assertSessionHasNoErrors();
        $this->act($this->user('PROC_STAFF'), $doc, 'next')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('PROC_DH', $doc->fresh()->status);
        $this->actingAs($this->user())->post(route('approvals.publish', $flow))->assertSessionHasErrors('flow');
        $this->assertSame(1, $flow->fresh()->version);
    }

    public function test_published_changes_do_not_reassign_an_existing_document(): void
    {
        $flow = ApprovalFlow::first();
        $owner = $this->user('PPC');
        $old = $this->document($owner);
        $this->act($owner, $old, 'submit')->assertRedirect()->assertSessionHasNoErrors();
        $flow->steps()->where('code', 'PROC_STAFF')->update(['role_code' => 'VIEWER']);
        app(ApprovalConfiguration::class)->publish($flow, $this->user());
        $new = $this->document($owner);
        $this->act($owner, $new, 'submit')->assertRedirect()->assertSessionHasNoErrors();
        $staff = $this->user('PROC_STAFF');
        $viewer = $this->user('VIEWER');
        $this->act($staff, $new, 'next')->assertForbidden();
        $this->act($viewer, $old, 'next')->assertForbidden();
        $this->act($staff, $old, 'next')->assertRedirect()->assertSessionHasNoErrors();
        $this->act($viewer, $new, 'next')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $old->fresh()->approval_snapshot['version']);
        $this->assertSame(2, $new->fresh()->approval_snapshot['version']);
    }

    public function test_assigning_specific_users_controls_task_queue_and_approval(): void
    {
        $flow = ApprovalFlow::first();
        $assigned = $this->user('VIEWER');
        $admin = $this->user();
        $step = $flow->steps()->where('code', 'PROC_STAFF')->first();
        $this->actingAs($admin)->post(route('approvals.steps.save', $flow), [...$step->only(['code', 'name', 'position', 'action', 'action_label', 'next_code', 'delegate_to', 'allow_return']), 'actor_mode' => 'users', 'user_ids' => [(string) $assigned->id]])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('approvals.publish', $flow))->assertRedirect()->assertSessionHasNoErrors();
        $doc = $this->document($this->user('PPC'));
        $this->act($doc->creator, $doc, 'submit')->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($assigned)->get('/documents?queue=1')->assertViewHas('total', 1);
        $this->act($this->user('PROC_STAFF'), $doc, 'next')->assertForbidden();
        $this->act($assigned, $doc, 'next')->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_custom_approval_stage_renders_and_executes_without_hardcoded_roles(): void
    {
        Role::create(['code' => 'GA', 'name' => 'GA Reviewer', 'permissions' => [], 'is_active' => true]);
        $flow = ApprovalFlow::first();
        $flow->steps()->where('code', 'PROC_DH')->update(['next_code' => 'GA_REVIEW']);
        $flow->steps()->create(['code' => 'GA_REVIEW', 'name' => 'Review GA', 'position' => 3, 'actor_mode' => 'role', 'role_code' => 'GA', 'action' => 'approve', 'action_label' => 'Setujui GA', 'next_code' => 'HRD']);
        app(ApprovalConfiguration::class)->publish($flow, $this->user());
        $doc = $this->document($this->user('PPC'));
        $this->act($doc->creator, $doc, 'submit')->assertRedirect()->assertSessionHasNoErrors();
        $this->act($this->user('PROC_STAFF'), $doc, 'next')->assertRedirect()->assertSessionHasNoErrors();
        $this->act($this->user('PROC_DH'), $doc, 'approve')->assertRedirect()->assertSessionHasNoErrors();
        $ga = $this->user('GA');
        $this->actingAs($ga)->get(route('documents.show', $doc))->assertOk()->assertSee('Setujui GA')->assertSee('Review GA');
        $this->get('/documents?queue=1')->assertViewHas('total', 1);
        $this->act($ga, $doc, 'approve')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('HRD', $doc->fresh()->status);
        $this->assertSame('Setujui GA', $doc->events()->reorder('id', 'desc')->first()->action_label);
    }

    public function test_publishing_rejects_cycles_and_routes_that_skip_signed_handover(): void
    {
        $flow = ApprovalFlow::first();
        $this->actingAs($this->user());
        $flow->steps()->where('code', 'HRD')->update(['next_code' => 'PROC_DH']);
        $this->post(route('approvals.publish', $flow))->assertSessionHasErrors('flow');
        $flow->steps()->where('code', 'HRD')->update(['next_code' => 'SECURITY']);
        $this->post(route('approvals.publish', $flow))->assertSessionHasErrors('flow');
        $this->assertSame(1, $flow->fresh()->version);
        $this->assertTrue($flow->fresh()->is_active);
    }
}
