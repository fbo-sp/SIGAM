<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bloco "Segurança" da área de estudo (decisão de 28/10/2026): o mesmo texto da ficha passa a aparecer na tela do aluno. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technique_items', function (Blueprint $table): void {
            $table->string('safety_note', 300)->nullable()->after('common_error');
        });
    }

    public function down(): void
    {
        Schema::table('technique_items', function (Blueprint $table): void {
            $table->dropColumn('safety_note');
        });
    }
};
