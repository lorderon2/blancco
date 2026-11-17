<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_assignments', function (Blueprint $t) {
            $t->unsignedBigInteger('source_sequence')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('device_assignments', function (Blueprint $t) {
            $t->dropColumn('source_sequence');
        });
    }
};
