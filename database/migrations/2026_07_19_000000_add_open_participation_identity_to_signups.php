<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signups', function (Blueprint $table) {
            $table->string('participation_key_hash', 64)
                ->nullable()
                ->after('account_id');

            $table->unique(['sheet_id', 'participation_key_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('signups', function (Blueprint $table) {
            $table->dropUnique(['sheet_id', 'participation_key_hash']);
            $table->dropColumn('participation_key_hash');
        });
    }
};
