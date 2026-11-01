<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agt_submissoes', function (Blueprint $table) {
            // Mantém o timestamp original do pedido para repetir o mesmo payload
            // após timeout sem falsear o momento em que a AGT o aceitou.
            $table->timestamp('submission_timestamp')->nullable()->after('submission_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('agt_submissoes', function (Blueprint $table) {
            $table->dropColumn('submission_timestamp');
        });
    }
};
