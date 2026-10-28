<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Opção da associação: permitir marcar um grupo inteiro de técnicas na aula. Começa desligada (decisão de 28/10/2026). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('progression_settings', function (Blueprint $table): void {
            $table->boolean('allow_group_pick')->default(false)->after('assessment_reminder_weeks');
        });
    }

    public function down(): void
    {
        Schema::table('progression_settings', function (Blueprint $table): void {
            $table->dropColumn('allow_group_pick');
        });
    }
};
