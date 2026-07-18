<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signups', function (Blueprint $table) {
            $table->foreignId('account_id')
                ->nullable()
                ->after('sheet_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('email_snapshot')->nullable()->after('name_snapshot');
            $table->boolean('name_consent')->default(false)->after('phone_snapshot');
            $table->boolean('email_consent')->default(false)->after('name_consent');
            $table->boolean('phone_consent')->default(false)->after('email_consent');

            $table->unique(['sheet_id', 'email_snapshot']);
            $table->unique(['account_id', 'sheet_id']);
        });

        Schema::create('pending_account_associations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signup_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_account_associations');

        Schema::table('signups', function (Blueprint $table) {
            $table->dropUnique(['sheet_id', 'email_snapshot']);
            $table->dropUnique(['account_id', 'sheet_id']);
            $table->dropForeign(['account_id']);
            $table->dropColumn([
                'account_id',
                'email_snapshot',
                'name_consent',
                'email_consent',
                'phone_consent',
            ]);
        });
    }
};
