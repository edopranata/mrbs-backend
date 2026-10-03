<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Login kini memakai username. User yang sudah ada diberi username dari bagian
     * email sebelum "@" (mis. admin@kantor.test -> admin), ditambah angka bila sama.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable()->unique()->after('name');
        });

        $taken = [];
        DB::table('users')->orderBy('id')->each(function (object $user) use (&$taken) {
            $base = preg_replace('/[^a-z0-9._-]/', '', strtolower(strstr($user->email, '@', true) ?: $user->email));
            $base = substr($base, 0, 40);
            if (strlen($base) < 3) {
                $base = "user{$user->id}";
            }

            $username = $base;
            for ($i = 2; in_array($username, $taken, true); $i++) {
                $username = $base.$i;
            }
            $taken[] = $username;

            DB::table('users')->where('id', $user->id)->update(['username' => $username]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
