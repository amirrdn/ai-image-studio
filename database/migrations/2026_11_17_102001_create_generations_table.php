<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pixelforge_id')->nullable()->unique();
            $table->uuid('idempotency_key')->unique();
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_urls')->nullable();
            $table->string('status')->default('pending')->index();
            $table->json('pricing_snapshot');
            $table->decimal('estimated_provider_cost_min', 18, 9);
            $table->decimal('estimated_provider_cost_max', 18, 9);
            $table->decimal('estimated_user_cost_min', 18, 9);
            $table->decimal('estimated_user_cost_max', 18, 9);
            $table->decimal('actual_provider_cost', 18, 9)->nullable();
            $table->decimal('actual_user_cost', 18, 9)->nullable();
            $table->json('token_usage')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamp('image_expires_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};

