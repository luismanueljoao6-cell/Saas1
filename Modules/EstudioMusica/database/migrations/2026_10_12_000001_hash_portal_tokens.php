<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Existing portal URLs continue to work: hash the stored bearer token
     * in place, then compare future requests against the hash of the token
     * they present. This migration is intentionally irreversible because
     * the original bearer tokens cannot be recovered from SHA-256.
     */
    public function up(): void
    {
        DB::table('clientes')
            ->whereNotNull('token_portal')
            ->orderBy('id')
            ->chunkById(500, function ($clientes): void {
                foreach ($clientes as $cliente) {
                    $token = (string) $cliente->token_portal;

                    // Allows safe resumption if a migration run was interrupted.
                    if (preg_match('/^[a-f0-9]{64}$/', $token) === 1) {
                        continue;
                    }

                    DB::table('clientes')
                        ->where('id', $cliente->id)
                        ->update(['token_portal' => hash('sha256', $token)]);
                }
            });
    }

    public function down(): void
    {
        // Irreversible data migration: the original tokens are not recoverable.
    }
};
