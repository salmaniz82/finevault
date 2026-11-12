<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class CinematicLoginTest extends TestCase
{
    use RefreshDatabase, \Tests\Support\WorkflowFixtures;

    private function account(string $role): array
    {
        $data = ['email' => $role.'@login.test', 'password' => 'local-test-only'];
        User::create($data + ['name' => 'Preview User', 'role' => $role]);
        return $data;
    }

    public function test_json_login_requires_correct_password_and_preserves_intended_destination_and_remember(): void
    {
        $data = $this->account('admin');
        $this->postJson('/login', array_merge($data, ['password' => 'incorrect']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest();
        $this->withSession(['url.intended' => url('/activity')])
            ->postJson('/login', $data + ['remember' => '1'])
            ->assertOk()->assertJsonPath('destination', url('/activity'))
            ->assertCookie(Auth::guard()->getRecallerName());
        $this->assertAuthenticated();
    }

    public function test_json_destination_obeys_client_onboarding_gate(): void
    {
        $response = $this->postJson('/login', $this->account('client'))->assertOk();
        $this->get($response->json('destination'))->assertRedirect('/onboarding');
    }

    public function test_normal_form_submission_still_redirects(): void
    {
        $this->post('/login', $this->account('manager'))->assertRedirect('/dashboard');
    }

    public function test_approved_client_without_released_holdings_arrives_at_agreements(): void
    {
        $data = $this->account('client');
        $this->approveClient(User::where('email', $data['email'])->firstOrFail());
        $response = $this->postJson('/login', $data)->assertOk();
        $this->get($response->json('destination'))->assertRedirect('/deals');
    }
}
