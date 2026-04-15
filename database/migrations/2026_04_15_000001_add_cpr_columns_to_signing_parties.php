<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signing_parties', function (Blueprint $table) {
            $table->text('cpr_encrypted')->nullable()->after('cpr_last_four');
            $table->string('cpr_hash', 64)->nullable()->after('cpr_encrypted');

            $table->index('cpr_hash');
        });
    }

    public function down(): void
    {
        Schema::table('signing_parties', function (Blueprint $table) {
            $table->dropIndex(['cpr_hash']);
            $table->dropColumn(['cpr_encrypted', 'cpr_hash']);
        });
    }
};
