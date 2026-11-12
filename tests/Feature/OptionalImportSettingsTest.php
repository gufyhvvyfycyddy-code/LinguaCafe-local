<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Settings\SettingValueService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OptionalImportSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');

        Schema::connection('sqlite')->create('settings', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id');
            $table->string('name');
            $table->text('value');
            $table->timestamps();
        });
    }

    public function test_missing_optional_settings_default_to_disabled_without_creating_rows(): void
    {
        $service = app(SettingValueService::class);

        $this->assertFalse($service->isJellyfinEnabled());
        $this->assertSame([
            'ankiAutoAddCards' => false,
            'ankiShowNotifications' => false,
        ], $service->getAnkiSettings()->all());
        $this->assertSame(0, Setting::count());
    }

    public function test_existing_jellyfin_true_and_false_are_preserved(): void
    {
        $setting = Setting::forceCreate([
            'user_id' => -1,
            'name' => 'jellyfinEnabled',
            'value' => 'true',
        ]);

        $service = app(SettingValueService::class);
        $this->assertTrue($service->isJellyfinEnabled());

        $setting->value = 'false';
        $setting->save();
        $this->assertFalse($service->isJellyfinEnabled());
    }

    public function test_partial_anki_settings_keep_existing_values_and_disable_missing_option(): void
    {
        Setting::forceCreate([
            'user_id' => -1,
            'name' => 'ankiShowNotifications',
            'value' => 'true',
        ]);

        $service = app(SettingValueService::class);
        $this->assertSame([
            'ankiAutoAddCards' => false,
            'ankiShowNotifications' => true,
        ], $service->getAnkiSettings()->all());

        Setting::forceCreate([
            'user_id' => -1,
            'name' => 'ankiAutoAddCards',
            'value' => 'true',
        ]);
        $this->assertSame([
            'ankiAutoAddCards' => true,
            'ankiShowNotifications' => true,
        ], $service->getAnkiSettings()->all());
    }
}
