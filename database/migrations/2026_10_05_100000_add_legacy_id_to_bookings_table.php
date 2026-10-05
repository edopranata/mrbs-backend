<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // ID booking asal di MRBS lama (mrbs_entry.id). Diisi oleh `mrbs:import-legacy` agar impor
            // bisa dijalankan ulang tanpa menggandakan data; null untuk booking yang dibuat di aplikasi ini.
            $table->unsignedBigInteger('legacy_id')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique(['legacy_id']);
            $table->dropColumn('legacy_id');
        });
    }
};
