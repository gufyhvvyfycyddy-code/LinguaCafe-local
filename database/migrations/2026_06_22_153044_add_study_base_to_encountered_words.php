<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('encountered_words', function (Blueprint $table) {
            $table->string('study_base', 255)->nullable()->after('lemma');
            $table->index('study_base');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('encountered_words', function (Blueprint $table) {
            $table->dropIndex(['study_base']);
            $table->dropColumn('study_base');
        });
    }
};
