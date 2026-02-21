<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signing_envelopes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 32)->default('draft');
            $table->string('original_document', 512);
            $table->string('signed_document', 512)->nullable();
            $table->string('idura_signature_order_id')->nullable();
            $table->tinyInteger('total_rounds')->unsigned()->default(1);
            $table->tinyInteger('current_round')->unsigned()->default(1);
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('reminder_interval')->nullable();
            $table->timestamp('last_reminder_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signing_envelopes');
    }
};
