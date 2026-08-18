<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class M9MobileTextImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_ios_text_import_reuses_existing_import_service_and_replays_exactly_once(): void
    {
        $user = $this->createUser('m9-ios@example.test', 'english');
        $token = $this->issueToken($user);
        $actionId = (string) Str::uuid();
        $payload = [
            'client_action_id' => $actionId,
            'file_name' => 'reader.txt',
            'content' => 'This is a short English text for the iOS document picker.',
            'book_name' => 'iOS Reader',
            'chapter_name' => 'Imported text',
        ];

        $imports = $this->mock(ImportService::class);
        $imports->shouldReceive('importText')
            ->once()
            ->with(
                $user->id,
                $user->uuid,
                3000,
                'detailed',
                $payload['content'],
                -1,
                $payload['book_name'],
                $payload['chapter_name'],
            )
            ->andReturn('fallback');

        $first = $this->withToken($token)
            ->postJson('/api/v1/mobile/imports/text', $payload)
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.processing_mode', 'fallback')
            ->assertJsonPath('data.replayed', false);

        $second = $this->withToken($token)
            ->postJson('/api/v1/mobile/imports/text', $payload)
            ->assertCreated()
            ->assertJsonPath('data.replayed', true);

        $this->assertSame($first->json('data.operation_id'), $second->json('data.operation_id'));
        $this->assertDatabaseCount('mobile_client_actions', 1);

        $this->withToken($token)
            ->postJson('/api/v1/mobile/imports/text', [
                ...$payload,
                'content' => 'Different content must not reuse the same action identity.',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    }

    public function test_mobile_text_import_is_english_only_and_device_authenticated(): void
    {
        $payload = [
            'client_action_id' => (string) Str::uuid(),
            'file_name' => 'reader.txt',
            'content' => 'English-shaped content is not enough to cross the selected-language boundary.',
            'book_name' => 'Reader',
            'chapter_name' => 'Imported text',
        ];

        $this->postJson('/api/v1/mobile/imports/text', $payload)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $user = $this->createUser('m9-non-english@example.test', 'spanish');
        $token = $this->issueToken($user);
        $user->refresh()->forceFill(['selected_language' => 'spanish'])->save();
        $this->mock(ImportService::class)->shouldNotReceive('importText');

        $this->withToken($token)
            ->postJson('/api/v1/mobile/imports/text', $payload)
            ->assertConflict()
            ->assertJsonPath('error.code', 'ENGLISH_LANGUAGE_REQUIRED');
    }

    public function test_mobile_text_import_rejects_unsupported_or_oversized_documents(): void
    {
        $user = $this->createUser('m9-validation@example.test', 'english');
        $token = $this->issueToken($user);
        $base = [
            'client_action_id' => (string) Str::uuid(),
            'book_name' => 'Reader',
            'chapter_name' => 'Imported text',
        ];

        $this->withToken($token)
            ->postJson('/api/v1/mobile/imports/text', [
                ...$base,
                'file_name' => 'reader.pdf',
                'content' => 'Not a text document.',
            ])
            ->assertUnprocessable();

        $this->withToken($token)
            ->postJson('/api/v1/mobile/imports/text', [
                ...$base,
                'client_action_id' => (string) Str::uuid(),
                'file_name' => 'reader.txt',
                'content' => str_repeat('a', 200001),
            ])
            ->assertUnprocessable();

        $this->withToken($token)
            ->postJson('/api/v1/mobile/imports/text', [
                ...$base,
                'client_action_id' => (string) Str::uuid(),
                'file_name' => 'reader.txt',
                'content' => " \n\t ",
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertDatabaseCount('mobile_client_actions', 0);
    }

    private function issueToken(User $user): string
    {
        $deviceUuid = (string) Str::uuid();

        return $this->postJson('/api/v1/mobile/auth/tokens', [
            'email' => $user->email,
            'password' => 'password',
            'device_uuid' => $deviceUuid,
            'platform' => 'ios',
            'device_name' => 'M9 iOS test device',
            'app_version' => '1.0.0',
        ])->assertCreated()->json('data.token');
    }

    private function createUser(string $email, string $language): User
    {
        return User::forceCreate([
            'name' => Str::before($email, '@'),
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => $language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }
}
