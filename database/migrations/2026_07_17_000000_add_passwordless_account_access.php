<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->normalizeExistingAccountEmails();

        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
            $table->string('password')->nullable()->change();
        });

        Schema::create('account_access_challenges', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('email')->index();
            $table->string('code_hash');
            $table->string('token_hash');
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX account_access_challenges_one_live_per_email
            ON account_access_challenges (email)
            WHERE used_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('account_access_challenges');

        DB::table('users')->whereNull('name')->update(['name' => 'Account']);
        DB::table('users')->whereNull('password')->update([
            'password' => Hash::make(Str::random(64)),
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }

    public function normalizeExistingAccountEmails(): void
    {
        $accounts = DB::table('users')
            ->select(['id', 'email'])
            ->orderBy('id')
            ->get();

        $accountIdsByEmail = [];
        $normalizedEmailsByAccountId = [];

        foreach ($accounts as $account) {
            $accountId = (int) $account->id;
            $normalizedEmail = Str::lower(trim((string) $account->email));

            if (isset($accountIdsByEmail[$normalizedEmail])
                && $accountIdsByEmail[$normalizedEmail] !== $accountId) {
                throw new RuntimeException(
                    "Accounts {$accountIdsByEmail[$normalizedEmail]} and {$accountId} normalize to the same address.",
                );
            }

            $accountIdsByEmail[$normalizedEmail] = $accountId;
            $normalizedEmailsByAccountId[$accountId] = $normalizedEmail;
        }

        foreach ($normalizedEmailsByAccountId as $accountId => $normalizedEmail) {
            DB::table('users')
                ->where('id', $accountId)
                ->update(['email' => $normalizedEmail]);
        }
    }
};
