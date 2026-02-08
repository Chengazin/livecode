<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('
            CREATE OR REPLACE FUNCTION update_updated_at_column()
            RETURNS TRIGGER AS $$
            BEGIN
                NEW.updated_at = CURRENT_TIMESTAMP;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        ');

        DB::unprepared('
            CREATE TRIGGER trigger_users_updated_at
            BEFORE UPDATE ON users
            FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
        ');

        DB::unprepared('
            CREATE TRIGGER trigger_projects_updated_at
            BEFORE UPDATE ON projects
            FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
        ');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trigger_projects_updated_at ON projects;');
        DB::unprepared('DROP TRIGGER IF EXISTS trigger_users_updated_at ON users;');
        DB::unprepared('DROP FUNCTION IF EXISTS update_updated_at_column();');
    }
};
