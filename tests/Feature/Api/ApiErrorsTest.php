<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiErrorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_missing_record_answers_not_found_without_internal_names(): void
    {
        Route::middleware('api')->get('/api/test-missing/{id}', fn (int $id) => User::findOrFail($id));

        $this->getJson('/api/test-missing/999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not found.']);
    }

    public function test_an_unknown_api_address_answers_not_found(): void
    {
        $this->get('/api/does-not-exist')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not found.']);
    }

    public function test_validation_errors_use_the_standard_shape(): void
    {
        $this->postJson('/api/login', [], ['Origin' => 'http://localhost'])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['email', 'password']]);
    }
}
