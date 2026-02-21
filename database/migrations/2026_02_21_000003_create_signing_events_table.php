<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signing_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signing_envelope_id')->constrained('signing_envelopes')->cascadeOnDelete();
            $table->foreignId('signing_party_id')->nullable()->constrained('signing_parties')->nullOnDelete();
            $table->string('event_type', 64);
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at');

            $table->index(['signing_envelope_id', 'created_at']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signing_events');
    }
};
