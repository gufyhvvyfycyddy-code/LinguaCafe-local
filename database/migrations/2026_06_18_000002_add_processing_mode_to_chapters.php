<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddProcessingModeToChapters extends Migration
{
    public function up()
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->string('processing_mode')->default('tokenizer');
        });
    }

    public function down()
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->dropColumn('processing_mode');
        });
    }
}
