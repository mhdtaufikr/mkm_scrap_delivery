<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RequestedAdminSeeder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Azure\Provider;
use SocialiteProviders\Manager\Config;
use Tests\TestCase;

class SsoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['graph.microsoft.com/*' => Http::response([], 404)]);
    }

    private const TENANT = '11111111-1111-4111-8111-111111111111';

    private const OBJECT = '22222222-2222-4222-8222-222222222222';

    private function enable(): void
    {
        config(['services.azure.enabled' => true, 'services.azure.tenant' => self::TENANT, 'services.azure.client_id' => 'client-id', 'services.azure.client_secret' => 'test-secret', 'services.azure.redirect' => 'http://localhost/auth/azure/callback']);
    }

    private function provider(array &$history, string $id = self::OBJECT, string $email = 'sso@example.com'): void
    {
        $handler = new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'test-access-token', 'expires_in' => 3600])),
            new Response(200, [], json_encode(['id' => $id, 'displayName' => 'SSO Name', 'userPrincipalName' => $email, 'mail' => $email])),
        ]);
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($history));
        $client = new Client(['handler' => $stack]);
        Socialite::shouldReceive('driver')->with('azure')->andReturnUsing(function () use ($client) {
            return (new Provider(request(), 'client-id', 'test-secret', 'http://localhost/auth/azure/callback'))->setConfig(new Config('client-id', 'test-secret', 'http://localhost/auth/azure/callback', ['tenant' => self::TENANT]))->setHttpClient($client);
        });
    }

    public function test_disabled_and_incomplete_sso_does_not_contact_provider(): void
    {
        Socialite::shouldReceive('driver')->never();
        config(['services.azure.enabled' => false]);
        $this->get('/auth/azure')->assertRedirect(route('login'))->assertSessionHasErrors('sso');
        $this->enable();
        config(['services.azure.tenant' => 'common']);
        $this->get('/auth/azure')->assertRedirect(route('login'))->assertSessionHasErrors('sso');
        $this->get('/MKM@dm1n')->assertDontSee('Sign in with MKM (SSO)');
    }

    public function test_registered_provider_redirects_to_pinned_tenant_with_state(): void
    {
        $this->enable();
        $response = $this->get('/auth/azure');
        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://login.microsoftonline.com/'.self::TENANT.'/oauth2/v2.0/authorize?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertNotEmpty($query['state']);
        $this->assertSame('code', $query['response_type']);
        $response->assertSessionHas('state', $query['state']);
    }

    public function test_callback_validates_state_before_any_token_request(): void
    {
        $this->enable();
        $history = [];
        $this->provider($history);
        $this->withSession(['state' => 'expected'])->get('/auth/azure/callback?state=wrong&code=unused')->assertRedirect(route('login'))->assertSessionHasErrors('sso');
        $this->assertSame([], $history);
        $this->assertGuest();
    }

    public function test_sso_authenticates_only_prelinked_active_identity_and_keeps_role(): void
    {
        $this->enable();
        $user = User::factory()->create(['role' => 'HRD', 'sso_tenant_id' => self::TENANT, 'sso_object_id' => self::OBJECT, 'local_login_enabled' => false, 'must_change_password' => true]);
        $history = [];
        $this->provider($history);
        $this->withSession(['state' => 'expected'])->get('/auth/azure/callback?state=expected&code=code')->assertRedirect(route('dashboard'))->assertSessionHas('auth_method', 'sso');
        $this->assertAuthenticatedAs($user);
        $this->assertSame('HRD', $user->fresh()->role);
        $this->assertSame('SSO Name', $user->fresh()->name);
        $this->assertSame(2, count($history));
        $this->assertStringContainsString(self::TENANT.'/oauth2/v2.0/token', (string) $history[0]['request']->getUri());
        $this->assertSame('https://graph.microsoft.com/v1.0/me', (string) $history[1]['request']->getUri());
        $this->get('/home')->assertOk();
        $this->get('/password')->assertForbidden();
        $this->post('/logout');
        $this->post('/MKM@dm1n', ['login' => $user->email, 'password' => 'password'])->assertSessionHasErrors('login');
    }

    public function test_email_match_does_not_auto_link_or_create_an_account(): void
    {
        $this->enable();
        $user = User::factory()->create(['role' => 'ADMIN', 'email' => 'sso@example.com']);
        $history = [];
        $this->provider($history);
        $this->withSession(['state' => 'expected'])->get('/auth/azure/callback?state=expected&code=code')->assertRedirect(route('login'))->assertSessionHasErrors('sso');
        $this->assertGuest();
        $this->assertSame(1, User::count());
        $this->assertNull($user->fresh()->sso_object_id);
    }

    public function test_inactive_prelinked_identity_is_rejected(): void
    {
        $this->enable();
        User::factory()->create(['role' => 'PPC', 'is_active' => false, 'sso_tenant_id' => self::TENANT, 'sso_object_id' => self::OBJECT]);
        $history = [];
        $this->provider($history);
        $this->withSession(['state' => 'expected'])->get('/auth/azure/callback?state=expected&code=code')->assertRedirect(route('login'))->assertSessionHasErrors('sso');
        $this->assertGuest();
    }

    public function test_sso_binding_is_admin_only_unique_and_revokes_old_sessions(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $user = User::factory()->create(['role' => 'HRD']);
        $payload = ['role' => 'HRD', 'is_active' => 1, 'local_login_enabled' => '0', 'sso_tenant_id' => self::TENANT, 'sso_object_id' => self::OBJECT];
        $this->actingAs($user)->patch(route('users.update', $user), $payload)->assertForbidden();
        DB::table('sessions')->insert(['id' => 'old-local-session', 'user_id' => $user->id, 'payload' => 'old', 'last_activity' => time()]);
        $this->actingAs($admin)->patch(route('users.update', $user), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('sessions', ['id' => 'old-local-session']);
        $this->assertFalse($user->fresh()->local_login_enabled);
        $other = User::factory()->create(['role' => 'HRD']);
        $this->patch(route('users.update', $other), $payload)->assertSessionHasErrors('sso_object_id');
        $this->actingAs($user->fresh())->withSession(['auth_method' => 'local'])->get('/home')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_sso_only_mode_requires_complete_identity_and_local_disabled_accounts_cannot_login(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $user = User::factory()->create(['role' => 'VIEWER']);
        $this->actingAs($admin)->patch(route('users.update', $user), ['role' => 'VIEWER', 'is_active' => 1, 'local_login_enabled' => '0', 'sso_tenant_id' => null, 'sso_object_id' => null])->assertStatus(422);
        $this->patch(route('users.update', $user), ['role' => 'VIEWER', 'is_active' => 1, 'sso_tenant_id' => [], 'sso_object_id' => self::OBJECT])->assertSessionHasErrors('sso_tenant_id');
        $user->update(['local_login_enabled' => false, 'sso_tenant_id' => self::TENANT, 'sso_object_id' => self::OBJECT]);
        $this->post('/logout');
        $this->post('/MKM@dm1n', ['login' => $user->email, 'password' => 'password'])->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_admin_login_entry_ends_pending_or_approved_session_and_allows_local_login(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        foreach (['pending', 'approved'] as $status) {
            $user = User::factory()->create(['access_status' => $status, 'local_login_enabled' => false]);
            $this->actingAs($user)->withSession(['auth_method' => 'sso', 'url.intended' => '/access', 'old_data' => 'private'])
                ->get('/MKM@dm1n')->assertOk()->assertSee('Sign in')
                ->assertSessionMissing('auth_method')->assertSessionMissing('url.intended')->assertSessionMissing('old_data');
            $this->assertGuest();
            $this->assertNotEmpty(session()->token());
            $this->post('/MKM@dm1n', ['login' => $admin->email, 'password' => 'password'])->assertRedirect(route('dashboard'));
            $this->assertAuthenticatedAs($admin);
            $this->assertSame($status, $user->fresh()->access_status);
        }
    }

    public function test_root_starts_sso_and_local_login_uses_requested_address(): void
    {
        $this->get('/')->assertRedirect(route('auth.azure'));
        $this->get('/login')->assertRedirect(route('auth.azure'));
        $this->get('/MKM@dm1n')->assertOk()->assertSee('Sign in')->assertSee(route('auth.login'));
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $this->actingAs($admin)->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_requested_admin_is_seeded_and_explicitly_linked_once(): void
    {
        $this->enable();
        config(['services.azure.bootstrap_admin_email' => 'mkm02120@ptmkm.co.id']);
        $this->seed(RequestedAdminSeeder::class);
        $admin = User::where('email', 'mkm02120@ptmkm.co.id')->firstOrFail();
        $this->assertSame('ADMIN', $admin->role);
        $this->assertTrue($admin->sso_link_pending);
        $password = $admin->password;
        $history = [];
        $this->provider($history, self::OBJECT, 'mkm02120@ptmkm.co.id');
        $this->withSession(['state' => 'expected'])->get('/auth/azure/callback?state=expected&code=code')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($admin);
        $this->assertSame(self::OBJECT, $admin->fresh()->sso_object_id);
        $this->assertFalse($admin->fresh()->sso_link_pending);
        $this->seed(RequestedAdminSeeder::class);
        $this->assertFalse($admin->fresh()->sso_link_pending);
        $this->assertSame('SSO Name', $admin->fresh()->name);
        $this->assertSame($password, $admin->fresh()->password);
        $this->get(route('users.index'))->assertOk();
    }

    public function test_pending_admin_is_not_linked_to_another_principal(): void
    {
        $this->enable();
        config(['services.azure.bootstrap_admin_email' => 'mkm02120@ptmkm.co.id']);
        $this->seed(RequestedAdminSeeder::class);
        $history = [];
        $this->provider($history, self::OBJECT, 'another@ptmkm.co.id');
        $this->withSession(['state' => 'expected'])->get('/auth/azure/callback?state=expected&code=code')->assertRedirect(route('access.show'));
        $this->assertSame('onboarding', auth()->user()->access_status);
        $this->assertFalse(auth()->user()->hasPermission('users.manage'));
        $this->assertFalse(auth()->user()->local_login_enabled);
        $this->get('/home')->assertRedirect(route('access.show'));
        $this->assertAuthenticated();
        $this->assertNull(User::where('email', 'mkm02120@ptmkm.co.id')->firstOrFail()->sso_object_id);
    }

    public function test_signed_in_user_can_refresh_microsoft_profile_without_logout(): void
    {
        $this->enable();
        $user = User::factory()->create(['role' => 'VIEWER', 'sso_tenant_id' => self::TENANT, 'sso_object_id' => self::OBJECT]);
        $history = [];
        $this->provider($history);
        $response = $this->actingAs($user)->post(route('profile.refresh'));
        $response->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->get('/auth/azure/callback?state='.$query['state'].'&code=code')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame('SSO Name', $user->fresh()->name);
    }

    public function test_account_mismatch_clears_session_and_restarts_sso_with_account_selection(): void
    {
        $this->enable();
        $current = User::factory()->create(['role' => 'VIEWER']);
        User::factory()->create(['role' => 'ADMIN', 'sso_tenant_id' => self::TENANT, 'sso_object_id' => self::OBJECT]);
        $history = [];
        $this->provider($history);
        $this->actingAs($current)->withSession(['state' => 'expected', 'auth_method' => 'local', 'url.intended' => '/users', 'old_session_data' => 'private'])
            ->get('/auth/azure/callback?state=expected&code=code')
            ->assertRedirect(route('auth.azure', ['select_account' => 1]))
            ->assertSessionMissing('auth_method')->assertSessionMissing('url.intended')->assertSessionMissing('old_session_data');
        $this->assertGuest();
        $response = $this->get(route('auth.azure', ['select_account' => 1]));
        $response->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('select_account', $query['prompt']);
        $this->assertNotEmpty($query['state']);
        $this->assertNull($current->fresh()->sso_object_id);
    }
}
