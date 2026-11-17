<?php

namespace Tests\Feature;

use App\Domain\Installations\ImportAssignment;
use App\Models\InstallationJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_and_registration_is_absent(): void
    {
        $this->get('/zlecenia')->assertRedirect('/login');
        $this->get('/register')->assertNotFound();
    }

    public function test_login_rotates_session_and_inactive_account_cannot_login(): void
    {
        $user = User::factory()->create(['password' => 'correct-password', 'active' => false]);
        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $user->update(['active' => true]);
        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password'])->assertRedirect('/zlecenia');
        $this->assertAuthenticatedAs($user);
    }

    public function test_user_can_create_view_and_cancel_job(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/zlecenia')->assertOk()->assertSee('Brak zleceń');
        $this->get('/zlecenia/nowe')->assertOk();
        $data = ['document_number' => 'REKA/1', 'request_key' => (string) Str::uuid(), 'serials' => "PF123\nPF456", 'source' => 'manual', 'country' => 'Polska', 'windows_version' => 'WINDOWS_11_PRO', 'use_dpk' => 1];
        $response = $this->post('/zlecenia', $data);
        $jobId = InstallationJob::oldest('id')->firstOrFail()->id;
        $response->assertRedirect('/zlecenia/'.$jobId);
        $this->assertDatabaseCount('installation_jobs', 2);
        $this->get('/zlecenia/'.$jobId)->assertOk()->assertSee('PF123')->assertSee('1WIN 11 PRO PL');
        $this->post('/zlecenia/'.$jobId.'/anuluj', ['reason' => 'Wycofano dokument'])->assertRedirect();
        $this->assertDatabaseHas('installation_jobs', ['id' => $jobId, 'lifecycle_status' => 'cancelled']);
        $this->post('/zlecenia', $data)->assertRedirect('/zlecenia/'.$jobId);
        $this->assertDatabaseCount('installation_jobs', 2);
        $this->assertDatabaseHas('installation_jobs', ['id' => $jobId, 'lifecycle_status' => 'cancelled']);
    }

    public function test_only_admin_can_create_users_but_both_roles_can_view(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/uzytkownicy')->assertOk();
        $data = ['name' => 'Operator', 'email' => 'operator@example.test', 'password' => 'long-password-123', 'password_confirmation' => 'long-password-123', 'role' => 'user'];
        $this->post('/uzytkownicy', $data)->assertForbidden();
        $user->update(['role' => 'admin']);
        $this->post('/uzytkownicy', $data)->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'operator@example.test', 'role' => 'user']);
    }

    public function test_disabled_session_is_revoked(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $user->update(['active' => false]);
        $this->get('/zlecenia')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_filters_and_pagination_render_without_frontend_build(): void
    {
        $this->actingAs(User::factory()->create())->get('/zlecenia?q=PF&state=expired&source=manual')->assertOk();
    }

    public function test_expired_filter_does_not_depend_on_scheduler_and_user_input_is_escaped(): void
    {
        $this->actingAs(User::factory()->create());
        $job = app(ImportAssignment::class)->import(['source' => 'manual', 'external_id' => '<script>alert(1)</script>', 'document_number' => '<script>alert(1)</script>',
            'serial' => 'SAFE123', 'windows_version' => 'WINDOWS_11_PRO', 'country' => 'Polska', 'use_dpk' => false, 'assigned_at' => now()]);
        $this->travel(4)->days();
        $this->get('/zlecenia?state=expired')->assertOk()->assertSee('SAFE123')->assertSee('Wygasłe')->assertDontSee('<script>alert(1)</script>', false);
        $this->get('/zlecenia?state=open')->assertOk()->assertDontSee('SAFE123');
    }

    public function test_same_manual_document_cannot_mix_system_configurations(): void
    {
        $this->actingAs(User::factory()->create());
        $data = ['document_number' => 'M/1', 'serials' => 'PF123', 'country' => 'Polska', 'windows_version' => 'WINDOWS_11_PRO', 'source' => 'manual'];
        $this->post('/zlecenia', $data)->assertRedirect();
        $data['serials'] = 'PF456';
        $data['windows_version'] = 'WINDOWS_11_HOME';
        $this->post('/zlecenia', $data)->assertSessionHasErrors('windows_version');
        $this->assertDatabaseCount('installation_jobs', 1);
    }
}
