<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Aniversariantes (decisão de 29/10/2026): o aluno ou o responsável pode esconder o aniversário dos colegas pelo portal. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table): void {
            $table->boolean('hide_birthday')->default(false)->after('birth_date');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table): void {
            $table->dropColumn('hide_birthday');
        });
    }
};
