<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sheet_id')->constrained()->cascadeOnDelete();
            $table->string('name_snapshot');
            $table->string('phone_snapshot')->nullable();
            $table->timestamps();
        });

        Schema::create('option_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['signup_id', 'option_id']);
            $table->index('option_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('option_claims');
        Schema::dropIfExists('signups');
    }
};
