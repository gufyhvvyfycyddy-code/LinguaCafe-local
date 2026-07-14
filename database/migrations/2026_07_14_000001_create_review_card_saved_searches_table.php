<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_card_saved_searches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('language_id', 64);
            $table->string('name', 80);
            $table->string('normalized_name', 80);
            $table->unsignedTinyInteger('filter_state_version')->default(1);
            $table->json('filter_state');
            $table->timestamps();

            $table->unique(
                ['user_id', 'language_id', 'normalized_name'],
                'review_saved_search_user_language_name_unique'
            );
            $table->index(
                ['user_id', 'language_id', 'updated_at'],
                'review_saved_search_user_language_updated_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_card_saved_searches');
    }
};
