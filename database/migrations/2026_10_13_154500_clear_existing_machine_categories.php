<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('machines')->update(['category' => null]);
    }

    public function down(): void
    {
        // Existing machine category values cannot be restored.
    }
};
