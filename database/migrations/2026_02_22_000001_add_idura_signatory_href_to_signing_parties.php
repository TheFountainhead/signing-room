<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signing_parties', function (Blueprint $table) {
            $table->string('idura_signatory_href', 2048)->nullable()->after('idura_signatory_id');
        });
    }

    public function down(): void
    {
        Schema::table('signing_parties', function (Blueprint $table) {
            $table->dropColumn('idura_signatory_href');
        });
    }
};
