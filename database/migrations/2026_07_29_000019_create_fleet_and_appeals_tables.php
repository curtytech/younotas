<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('plate', 10)->nullable();
            $table->string('renavam', 20)->nullable();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('type')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'plate']);
        });

        Schema::create('drivers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicle')->nullOnDelete();
            $table->string('name');
            $table->date('birth_date')->nullable();
            $table->text('description')->nullable();
            $table->string('cpf', 20)->nullable();
            $table->string('cnh', 30)->nullable();
            $table->date('cnh_expiration_date')->nullable();
            $table->date('toxicologic_exam_expiration_date')->nullable();
            $table->timestamps();
        });

        Schema::create('fines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicle')->nullOnDelete();
            $table->string('ait');
            $table->date('fine_date');
            $table->text('description')->nullable();
            $table->string('fine_article')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'ait']);
        });

        Schema::create('appeal_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('appeals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fine_id')->constrained('fines')->cascadeOnDelete();
            $table->foreignId('appeal_status_id')->constrained('appeal_statuses')->restrictOnDelete();
            $table->date('date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appeals');
        Schema::dropIfExists('appeal_statuses');
        Schema::dropIfExists('fines');
        Schema::dropIfExists('drivers');
        Schema::dropIfExists('vehicle');
    }
};
