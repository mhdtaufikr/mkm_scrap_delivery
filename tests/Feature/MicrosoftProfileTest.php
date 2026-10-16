<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MicrosoftProfileSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Socialite\Two\User as Identity;
use Tests\TestCase;

class MicrosoftProfileTest extends TestCase
{
    use RefreshDatabase;

    private function identity(User $user): Identity
    {
        return (new Identity)->setRaw(['id' => $user->sso_object_id, 'displayName' => 'Microsoft Name'])->map(['id' => $user->sso_object_id, 'name' => 'Microsoft Name'])->setToken('profile-test-token');
    }

    public function test_profile_and_photo_sync_without_changing_access_or_credentials(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $user = User::factory()->create(['role' => 'HRD', 'sso_object_id' => '22222222-2222-4222-8222-222222222222']);
        $password = $user->password;
        $email = $user->email;
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j5N8AAAAASUVORK5CYII=');
        Http::fake([
            'graph.microsoft.com/v1.0/me?*' => Http::response(['id' => $user->sso_object_id, 'displayName' => 'Full Microsoft Name', 'jobTitle' => 'Application Engineer', 'department' => 'Information Technology', 'role' => 'ADMIN', 'mail' => 'untrusted@example.com']),
            'graph.microsoft.com/v1.0/me/photo/*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);
        app(MicrosoftProfileSync::class)->sync($user, $this->identity($user));
        $user->refresh();
        $this->assertSame('Full Microsoft Name', $user->name);
        $this->assertSame('Application Engineer', $user->job_title);
        $this->assertSame('Information Technology', $user->department);
        $this->assertSame('HRD', $user->role);
        $this->assertSame($email, $user->email);
        $this->assertSame($password, $user->password);
        Storage::disk('local')->assertExists($user->avatar_path);
        Http::assertSent(fn ($request) => str_contains($request->url(), '$select=') || str_contains($request->url(), '%24select='));
        $this->actingAs($user)->get('/home')->assertOk()->assertSee('Full Microsoft Name')->assertSee('Application Engineer')->assertSee('Information Technology')->assertSee('profile/avatar');
        $this->get(route('profile.avatar'))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_optional_graph_failure_preserves_existing_job_department_and_avatar(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'VIEWER', 'job_title' => 'Existing title', 'department' => 'Existing department', 'sso_object_id' => '22222222-2222-4222-8222-222222222222']);
        $path = 'avatars/'.$user->id.'/existing.png';
        Storage::disk('local')->put($path, 'existing-photo');
        $user->update(['avatar_path' => $path]);
        Http::fake(['graph.microsoft.com/v1.0/me?*' => Http::response([], 503), 'graph.microsoft.com/v1.0/me/photo/*' => Http::response([], 404)]);
        app(MicrosoftProfileSync::class)->sync($user, $this->identity($user));
        $this->assertSame('Microsoft Name', $user->fresh()->name);
        $this->assertSame('Existing title', $user->fresh()->job_title);
        $this->assertSame('Existing department', $user->fresh()->department);
        $this->assertSame($path, $user->fresh()->avatar_path);
    }

    public function test_non_image_photo_and_wrong_identity_profile_are_ignored(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'VIEWER', 'department' => 'Original department', 'sso_object_id' => '22222222-2222-4222-8222-222222222222']);
        Http::fake(['graph.microsoft.com/v1.0/me?*' => Http::response(['id' => 'different-id', 'displayName' => 'Wrong Person', 'department' => 'Wrong department']), 'graph.microsoft.com/v1.0/me/photo/*' => Http::response('<svg>invalid</svg>', 200, ['Content-Type' => 'image/png'])]);
        app(MicrosoftProfileSync::class)->sync($user, $this->identity($user));
        $this->assertSame('Microsoft Name', $user->fresh()->name);
        $this->assertSame('Original department', $user->fresh()->department);
        $this->assertNull($user->fresh()->avatar_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->get(route('profile.avatar'))->assertRedirect(route('login'));
        $other = User::factory()->create(['role' => 'VIEWER']);
        $this->actingAs($other)->get(route('profile.avatar', ['user' => $user->id]))->assertNotFound();
    }

    public function test_section_requires_explicit_mapping_and_remains_separate_from_department(): void
    {
        $user = User::factory()->create(['role' => 'VIEWER', 'sso_object_id' => '22222222-2222-4222-8222-222222222222']);
        Http::fake(function ($request) use ($user) {
            if (str_contains($request->url(), '/photo/')) {
                return Http::response([], 404);
            }
            if (str_contains($request->url(), 'employeeOrgData')) {
                return Http::response(['id' => $user->sso_object_id, 'employeeOrgData' => ['division' => 'IT & Facility'], 'onPremisesExtensionAttributes' => ['extensionAttribute3' => 'Other organizational value']]);
            }

            return Http::response(['id' => $user->sso_object_id, 'displayName' => 'Full Name', 'jobTitle' => 'Sr. Staff', 'department' => 'ME-Engine']);
        });
        config(['services.azure.section_attribute' => null]);
        app(MicrosoftProfileSync::class)->sync($user, $this->identity($user));
        $this->assertNull($user->fresh()->section);
        $this->assertSame('ME-Engine', $user->fresh()->department);
        $this->assertSame('IT & Facility', $user->fresh()->organization_attributes['employeeOrgData']['division']);
        config(['services.azure.section_attribute' => 'employeeOrgData.division']);
        app(MicrosoftProfileSync::class)->sync($user, $this->identity($user));
        $this->assertSame('IT & Facility', $user->fresh()->section);
        $this->assertSame('microsoft', $user->fresh()->section_source);
        $this->actingAs($user)->get('/home')->assertOk()->assertSee('Section')->assertSee('IT &amp; Facility', false)->assertDontSee('action="'.route('logout').'"', false)->assertSee('Sync profile');
    }
}
