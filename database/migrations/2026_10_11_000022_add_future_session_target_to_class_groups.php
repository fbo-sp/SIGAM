<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_groups', function (Blueprint $table): void {
            $table->unsignedTinyInteger('future_session_target')->default(8)->after('capacity');
        });
    }

    public function down(): void
    {
        Schema::table('class_groups', function (Blueprint $table): void {
            $table->dropColumn('future_session_target');
        });
    }
};
