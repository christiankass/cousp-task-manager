<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domaine A : arborescence des structures (directions, divisions, services, cellules, équipes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained()->cascadeOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->string('name', 200);
            $table->string('code', 30);
            $table->string('unit_type', 30);
            $table->text('description')->nullable();
            $table->foreignUuid('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['institution_id', 'code']);
            // Cible des clés étrangères composées (même institution garantie).
            $table->unique(['institution_id', 'id']);
            $table->index(['institution_id', 'parent_id']);
        });

        // Contrainte 3 : un parent appartient forcément à la même institution.
        Schema::table('organization_units', function (Blueprint $table) {
            $table->foreign(['institution_id', 'parent_id'])
                ->references(['institution_id', 'id'])->on('organization_units');
        });

        Schema::create('user_unit_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('organization_unit_id');
            $table->string('membership_type', 30)->default('member');
            $table->boolean('is_primary')->default(false);
            $table->date('started_at')->nullable();
            $table->date('ended_at')->nullable();
            $table->timestamps();

            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();
            $table->foreign(['institution_id', 'organization_unit_id'])
                ->references(['institution_id', 'id'])->on('organization_units')->cascadeOnDelete();
            $table->unique(['organization_unit_id', 'user_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE organization_units ADD CONSTRAINT organization_units_unit_type_check CHECK (unit_type IN ('direction','division','department','service','cell','team'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_unit_memberships');
        Schema::dropIfExists('organization_units');
    }
};
