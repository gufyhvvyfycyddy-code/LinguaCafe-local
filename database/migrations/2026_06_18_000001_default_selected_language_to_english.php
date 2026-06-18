<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DefaultSelectedLanguageToEnglish extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('selected_language')->default('english')->change();
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('selected_language')->default('spanish')->change();
        });
    }
}
