<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->enum('role', ['admin', 'enterprise'])->default('admin')->index();
            $table->string('phone')->nullable();
            $table->string('cnpj')->nullable();
            $table->string('razao_social')->nullable();
            $table->string('inscricao_estatual')->nullable();
            $table->string('address')->nullable();
            $table->string('address_number')->nullable();
            $table->string('address_complement')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip_code')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        User::firstOrCreate(
            [
                'email' => 'admin@admin',
            ],
            [
                'name' => 'Admin Principal',
                'email_verified_at' => now(),
                'password' => Hash::make('12345678'),
                'role' => 'admin',
            ]
        );

        User::firstOrCreate(
            [
                'email' => 'enterprise@enterprise',
            ],
            [
                'name' => 'Empresa Principal',
                'email_verified_at' => now(),
                'password' => Hash::make('12345678'),
                'role' => 'enterprise',
            ]
        );

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
