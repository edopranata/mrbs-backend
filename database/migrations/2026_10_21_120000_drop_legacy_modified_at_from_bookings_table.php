<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sinkronisasi dari MRBS lama sudah tidak dipakai. `legacy_id` tetap disimpan sebagai penanda
 * booking hasil migrasi; versi data untuk sinkronisasi tidak diperlukan lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('legacy_modified_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('legacy_modified_at')->nullable()->after('legacy_id');
        });
    }
};
