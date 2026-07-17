<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_cards', function (Blueprint $table) {
            $table->unsignedTinyInteger('marker')->default(0)->after('lifecycle_changed_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('review_cards', function (Blueprint $table) {
            $table->dropIndex(['marker']);
            $table->dropColumn('marker');
        });
    }
};
