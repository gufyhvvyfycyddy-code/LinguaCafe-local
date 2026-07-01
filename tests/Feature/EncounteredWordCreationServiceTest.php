<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\EncounteredWordCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EncounteredWordCreationServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Ewc Service User',
            'email' => '__VG_EMAIL_ewc_service_1__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    public function test_service_can_be_invoked_directly(): void
    {
        $word = new \stdClass();
        $word->word = 'Hello';
        $word->lemma = 'hello';
        $word->reading = '';
        $word->lemma_reading = '';
        $word->phrase_ids = [];

        $service = new EncounteredWordCreationService();
        $service->create($this->user->id, 'english', [$word], ['hello']);

        $this->assertDatabaseHas('encountered_words', [
            'user_id' => $this->user->id,
            'language' => 'english',
            'word' => 'hello',
            'lemma' => 'hello',
        ]);
    }
}
