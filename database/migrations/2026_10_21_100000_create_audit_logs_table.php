<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // Pelaku; null = sistem (mis. sinkronisasi MRBS lama) atau login gagal tanpa akun.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Nama pelaku saat kejadian, tetap terbaca walau akunnya kemudian dihapus.
            $table->string('user_name')->nullable();
            $table->string('action', 50)->index();
            $table->string('subject_type', 30)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('description', 500);
            $table->json('changes')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
