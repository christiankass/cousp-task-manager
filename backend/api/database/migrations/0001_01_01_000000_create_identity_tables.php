<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domaine A/B : institutions, comptes utilisateurs (globaux) et adhésions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institutions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 200);
            $table->string('code', 30)->unique();
            $table->text('description')->nullable();
            $table->char('country_code', 2)->default('CD');
            $table->string('timezone', 60)->default('Africa/Kinshasa');
            $table->string('default_language', 5)->default('fr');
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->string('email', 190)->unique();
            $table->string('phone', 30)->nullable();
            $table->string('password');
            $table->string('preferred_language', 5)->default('fr');
            $table->boolean('is_platform_admin')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('institution_user_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamps();
            $table->unique(['institution_id', 'user_id']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('institution_user_memberships');
        Schema::dropIfExists('users');
        Schema::dropIfExists('institutions');
    }
};
