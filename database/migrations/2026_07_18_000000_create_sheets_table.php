<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('event_at')->nullable();
            $table->string('location')->nullable();
            $table->timestamp('deadline_at');
            $table->string('timezone');
            $table->string('state')->default('draft');
            $table->string('participation_policy')->default('open');
            $table->string('name_visibility')->default('owner_only');
            $table->string('email_visibility')->default('owner_only');
            $table->string('phone_visibility')->default('owner_only');
            $table->timestamps();

            $table->index(['owner_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sheets');
    }
};
