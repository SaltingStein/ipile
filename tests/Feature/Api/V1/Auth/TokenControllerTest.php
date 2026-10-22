<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TokenControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_the_users_tokens_and_marks_the_current_one(): void
    {
        $user = User::factory()->create();
        $tablet = $user->createToken('tablet');
        $phone = $user->createToken('phone');
        User::factory()->create()->createToken('someone-else');

        $response = $this->withToken($phone->plainTextToken)->getJson(route('api.v1.auth.tokens.index'));

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.id', $phone->accessToken->id);
        $response->assertJsonPath('data.0.is_current', true);
        $response->assertJsonPath('data.1.id', $tablet->accessToken->id);
        $response->assertJsonPath('data.1.is_current', false);
    }

    public function test_revokes_one_of_the_users_tokens(): void
    {
        $user = User::factory()->create();
        $phone = $user->createToken('phone');
        $tablet = $user->createToken('tablet');

        $this->withToken($phone->plainTextToken)
            ->deleteJson(route('api.v1.auth.tokens.destroy', $tablet->accessToken->id))
            ->assertNoContent();

        $this->assertModelMissing($tablet->accessToken);
    }

    public function test_another_users_token_returns_404_and_is_kept(): void
    {
        $user = User::factory()->create();
        $otherToken = User::factory()->create()->createToken('someone-else');

        $this->withToken($user->createToken('phone')->plainTextToken)
            ->deleteJson(route('api.v1.auth.tokens.destroy', $otherToken->accessToken->id))
            ->assertNotFound();

        $this->assertModelExists($otherToken->accessToken);
    }
}
