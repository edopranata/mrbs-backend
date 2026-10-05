<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Kolom `timestamp` entri di MRBS lama saat terakhir diimpor; dipakai `mrbs:sync-legacy`
            // untuk mengetahui booking yang diubah di MRBS lama.
            $table->dateTime('legacy_modified_at')->nullable()->after('legacy_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('legacy_modified_at');
        });
    }
};
