<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operations', function (Blueprint $table) {
            $table->timestamp('client_occurred_at', 6)
                ->nullable()
                ->after('request_fingerprint');
            $table->unsignedBigInteger('client_sequence')
                ->nullable()
                ->after('client_occurred_at');
            $table->uuid('batch_id')
                ->nullable()
                ->after('client_sequence');
            $table->index(
                [
                    'user_id',
                    'language_id',
                    'review_card_id',
                    'client_occurred_at',
                    'client_sequence',
                ],
                'operations_queued_rating_order_index',
            );
            $table->index(
                ['user_id', 'language_id', 'batch_id'],
                'operations_batch_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('operations', function (Blueprint $table) {
            $table->dropIndex('operations_queued_rating_order_index');
            $table->dropIndex('operations_batch_index');
            $table->dropColumn([
                'client_occurred_at',
                'client_sequence',
                'batch_id',
            ]);
        });
    }
};
