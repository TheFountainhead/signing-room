<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kort claim af fuldførelsen: sættes betinget, før Idura kaldes og PDF'en
 * gemmes, så to processer ikke fuldfører samme kuvert, uden at en rækkelås
 * holdes under HTTP-kald og disk-skrivning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signing_envelopes', function (Blueprint $table) {
            $table->timestamp('completion_started_at')->nullable()->after('completed_at');
            // Seneste vellykkede sync af kuverten: afgrænser alderen på en
            // underskrift, som sync finder ved polling.
            $table->timestamp('last_synced_at')->nullable()->after('completion_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('signing_envelopes', function (Blueprint $table) {
            $table->dropColumn(['completion_started_at', 'last_synced_at']);
        });
    }
};
