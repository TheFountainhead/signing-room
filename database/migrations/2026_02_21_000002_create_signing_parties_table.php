<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signing_parties', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('signing_envelope_id')->constrained('signing_envelopes')->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 32)->nullable();
            $table->char('cpr_last_four', 4)->nullable();
            $table->string('role', 128)->default('signer');
            $table->tinyInteger('signing_round')->unsigned()->default(1);
            $table->string('status', 32)->default('pending');
            $table->char('signing_token', 64)->unique();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->text('signature_data')->nullable();
            $table->string('idura_signatory_id')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->unsignedInteger('reminder_count')->default(0);
            $table->timestamps();

            $table->index(['signing_envelope_id', 'signing_round']);
            $table->index('email');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signing_parties');
    }
};
