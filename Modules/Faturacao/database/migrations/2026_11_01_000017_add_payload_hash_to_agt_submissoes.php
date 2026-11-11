<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agt_submissoes', function (Blueprint $table) {
            // Impede reutilizar o mesmo submissionUUID com um payload diferente.
            $table->char('submission_payload_hash', 64)->nullable()->after('submission_timestamp');
        });
    }

    public function down(): void
    {
        Schema::table('agt_submissoes', function (Blueprint $table) {
            $table->dropColumn('submission_payload_hash');
        });
    }
};
