<?php

namespace Tests\Unit;

use App\Models\Dictionary;
use App\Services\Dictionaries\DictionaryHealthService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DictionaryHealthServiceTest extends TestCase
{
    public function test_canonical_jmdict_target_requires_the_complete_five_table_group(): void
    {
        $dictionary = $this->dictionary(
            'A renamed JMDict metadata row',
            DictionaryHealthService::JMDICT_TARGET,
        );

        foreach ((new DictionaryHealthService())->jmdictSchema() as $tableName => $columns) {
            Schema::shouldReceive('hasTable')->once()->with($tableName)->andReturn(true);
            Schema::shouldReceive('hasColumns')->once()->with($tableName, $columns)->andReturn(true);
        }

        $health = (new DictionaryHealthService())->classify($dictionary);

        $this->assertSame('healthy', $health->status);
        $this->assertTrue($health->queryAvailable);
    }

    public function test_missing_any_jmdict_group_table_marks_the_whole_group_unavailable(): void
    {
        $service = new DictionaryHealthService();
        $dictionary = $this->dictionary('JMDict', DictionaryHealthService::JMDICT_TARGET);
        $targets = $service->jmdictSchema();

        foreach ($targets as $tableName => $columns) {
            if ($tableName === 'dict_jp_jmdict_readings') {
                Schema::shouldReceive('hasTable')->once()->with($tableName)->andReturn(false);
                break;
            }

            Schema::shouldReceive('hasTable')->once()->with($tableName)->andReturn(true);
            Schema::shouldReceive('hasColumns')->once()->with($tableName, $columns)->andReturn(true);
        }

        $health = $service->classify($dictionary);

        $this->assertSame('incomplete_group', $health->status);
        $this->assertSame('DICTIONARY_GROUP_INCOMPLETE', $health->code);
        $this->assertFalse($health->queryAvailable);
        $this->assertTrue($health->repairRequired);
    }

    public function test_jmdict_name_does_not_force_group_semantics_for_an_ordinary_target(): void
    {
        $dictionary = $this->dictionary('JMDict', 'dict_r11r_ordinary');

        Schema::shouldReceive('hasTable')->once()->with('dict_r11r_ordinary')->andReturn(true);
        Schema::shouldReceive('hasColumns')
            ->once()
            ->with('dict_r11r_ordinary', ['word', 'definitions'])
            ->andReturn(true);

        $health = (new DictionaryHealthService())->classify($dictionary);

        $this->assertSame('healthy', $health->status);
        $this->assertTrue($health->queryAvailable);
    }

    private function dictionary(string $name, string $target): Dictionary
    {
        $dictionary = new Dictionary();
        $dictionary->id = 41;
        $dictionary->name = $name;
        $dictionary->type = 'supported';
        $dictionary->database_table_name = $target;
        $dictionary->source_language = 'japanese';
        $dictionary->target_language = 'english';
        $dictionary->enabled = true;

        return $dictionary;
    }
}
