<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_type', 20)->default('preset')->after('language');
            $table->string('avatar_preset', 50)->nullable()->after('avatar_type');
            $table->string('avatar_path', 2048)->nullable()->after('avatar_preset');
        });

        DB::table('users')
            ->whereNull('avatar_preset')
            ->update(['avatar_preset' => 'robot']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'avatar_type',
                'avatar_preset',
                'avatar_path',
            ]);
        });
    }
};

