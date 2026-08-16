<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('material_type', 32)->default('personal')->after('language');
            $table->unsignedSmallInteger('exam_year')->nullable()->after('material_type');
            $table->unsignedTinyInteger('exam_set')->nullable()->after('exam_year');
        });

        Schema::table('chapters', function (Blueprint $table) {
            $table->string('question_type', 32)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->dropColumn('question_type');
        });

        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['material_type', 'exam_year', 'exam_set']);
        });
    }
};
