<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_hygiene_operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->unsignedBigInteger('user_id');
            $table->string('language_id', 64);
            $table->string('operation_type', 64);
            $table->string('status', 24)->default('applied');
            $table->json('subject_ids');
            $table->longText('before_snapshot');
            $table->longText('after_snapshot');
            $table->char('preview_fingerprint', 64);
            $table->json('metadata')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'language_id', 'operation_type', 'created_at'], 'knowledge_hygiene_scope_type_created');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_hygiene_operations');
    }
};
