<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_reset_only_with_valid_token(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post('/zapomniane-haslo', ['email' => $user->email])->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPassword::class);
        $token = Password::createToken($user);
        $this->get('/reset-hasla/'.$token.'?email='.urlencode($user->email))->assertOk();
        $this->post('/reset-hasla', ['token' => 'invalid', 'email' => $user->email, 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])->assertSessionHasErrors('email');
        $this->post('/reset-hasla', ['token' => $token, 'email' => $user->email, 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])->assertRedirect('/login');
        $this->assertTrue(Hash::check('new-password-123', $user->refresh()->password));
        $this->post('/reset-hasla', ['token' => $token, 'email' => $user->email, 'password' => 'other-password-123', 'password_confirmation' => 'other-password-123'])->assertSessionHasErrors('email');
    }

    public function test_reset_does_not_reveal_unknown_or_inactive_accounts(): void
    {
        Notification::fake();
        $user = User::factory()->create(['active' => false]);
        $this->post('/zapomniane-haslo', ['email' => $user->email])->assertSessionHas('status');
        $this->post('/zapomniane-haslo', ['email' => 'unknown@example.test'])->assertSessionHas('status');
        Notification::assertNothingSent();
    }
}
