<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Téléphones enregistrés pour les notifications push (application mobile). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 255);
            $table->string('platform', 20);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['institution_id', 'token']);
            $table->index(['institution_id', 'user_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE device_tokens ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE device_tokens FORCE ROW LEVEL SECURITY');
            DB::statement(<<<'SQL'
                CREATE POLICY tenant_isolation ON device_tokens
                USING (current_setting('app.bypass_rls', true) = 'on' OR institution_id::text = current_setting('app.institution_id', true))
                WITH CHECK (current_setting('app.bypass_rls', true) = 'on' OR institution_id::text = current_setting('app.institution_id', true))
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
