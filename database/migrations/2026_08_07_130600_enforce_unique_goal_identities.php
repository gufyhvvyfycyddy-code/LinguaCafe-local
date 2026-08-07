<?php

use App\Support\GoalIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        GoalIdentity::addConstraint(DB::connection());
    }

    public function down(): void
    {
        GoalIdentity::removeConstraint(DB::connection());
    }
};
