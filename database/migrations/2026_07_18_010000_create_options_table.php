<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE "options" (
                "id" integer PRIMARY KEY AUTOINCREMENT NOT NULL,
                "sheet_id" integer NOT NULL,
                "name" varchar NOT NULL,
                "description" text NULL,
                "capacity" integer NOT NULL CHECK ("capacity" > 0),
                "position" integer NOT NULL CHECK ("position" > 0),
                "created_at" datetime NULL,
                "updated_at" datetime NULL,
                FOREIGN KEY ("sheet_id") REFERENCES "sheets" ("id") ON DELETE CASCADE
            )
            SQL);

        Schema::table('options', function (Blueprint $table) {
            $table->index(['sheet_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('options');
    }
};
