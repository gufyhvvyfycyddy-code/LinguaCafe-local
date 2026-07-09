<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a nullable json column `understanding_aid` to `word_senses`.
 *
 * The column bundles four optional sub-fields that help the learner
 * understand why an example represents a sense:
 *   - explanation:       short prose, e.g. "bank = 金融机构"
 *   - meaning_boundary:  short prose distinguishing this sense from
 *                        nearby senses (e.g. "bank = 河岸" vs "银行")
 *   - context_hint:      short prose describing the current example's
 *                        context, e.g. "用户去银行办理事务"
 *   - usage_keywords:    list of common collocations, e.g.
 *                        ["go to the bank", "bank account", "loan"]
 *
 * All sub-fields are optional. When the column is null or all sub-fields
 * are empty, the SenseReview UI hides the "理解辅助" block entirely.
 *
 * This migration does NOT change FSRS, ReviewCard, ReviewLog, or any
 * rotation logic. The column is read-only during serialize.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('word_senses', function (Blueprint $table) {
            $table->json('understanding_aid')->nullable()->after('collocations');
        });
    }

    public function down(): void
    {
        Schema::table('word_senses', function (Blueprint $table) {
            $table->dropColumn('understanding_aid');
        });
    }
};
