<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('idempotency_key', 191);
            $table->string('channel', 32);
            $table->string('provider', 64)->nullable();
            $table->text('recipient');
            $table->text('payload');
            $table->string('status', 32)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->uuid('lock_token')->nullable();
            $table->string('provider_message_id', 191)->nullable();
            $table->json('provider_response')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(
                ['empresa_id', 'idempotency_key', 'channel'],
                'notification_outbox_idempotency_unique'
            );
            // Também permite uma FK composta nos registos de tentativas,
            // impedindo que uma tentativa seja associada a outra empresa.
            $table->unique(['id', 'empresa_id'], 'notification_outbox_id_empresa_unique');
            $table->index(['status', 'available_at'], 'notification_outbox_dispatch_idx');
            $table->index(['status', 'locked_at'], 'notification_outbox_recovery_idx');
            $table->index(['provider', 'provider_message_id'], 'notification_outbox_provider_idx');
        });

        Schema::create('notification_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('notification_outbox_id');
            $table->unsignedSmallInteger('attempt_number');
            $table->string('status', 32);
            $table->string('provider', 64)->nullable();
            $table->string('provider_message_id', 191)->nullable();
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['notification_outbox_id', 'attempt_number'],
                'notification_attempt_number_unique'
            );
            $table->foreign(
                ['notification_outbox_id', 'empresa_id'],
                'notification_attempt_outbox_tenant_fk'
            )->references(['id', 'empresa_id'])
                ->on('notification_outbox')
                ->cascadeOnDelete();
            $table->index(['empresa_id', 'status', 'started_at'], 'notification_attempt_tenant_status_idx');
            $table->index(['provider', 'provider_message_id'], 'notification_attempt_provider_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_attempts');
        Schema::dropIfExists('notification_outbox');
    }
};
