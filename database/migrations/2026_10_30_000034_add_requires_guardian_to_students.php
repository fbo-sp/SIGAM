<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Responsáveis (decisão de Fabio em 30/10/2026): nem todo aluno que precisa de responsável é menor de idade. A marcação
 * vale para maiores com alguma condição que exija acompanhamento, sem registrar o diagnóstico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->boolean('requires_guardian')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->dropColumn('requires_guardian');
        });
    }
};
