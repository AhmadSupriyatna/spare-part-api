<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Nama Aplikasi" + logo — the app's own in-product branding (sidebar
 * mark, browser tab title), editable by Superadmin only. Deliberately
 * separate from `name`/`logo_path`, which is the company's identity on
 * printed paperwork (QR labels, FP3, Laporan Maintenance) and stays
 * editable by Admin Spare Part too — these are two different concerns
 * that happen to share one singleton settings row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('app_name')->nullable()->after('name');
            $table->string('app_logo_path')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['app_name', 'app_logo_path']);
        });
    }
};
