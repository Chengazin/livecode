<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_participants', function (Blueprint $table) {
            $table->string('role', 32)
                ->default('developer')
                ->after('user_id');
        });

        DB::table('project_participants')
            ->whereNull('role')
            ->update(['role' => 'developer']);
    }

    public function down(): void
    {
        Schema::table('project_participants', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
