<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Dica curta do professor por item avaliado, mostrada ao aluno no portal (a observação geral continua interna). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technique_assessments', function (Blueprint $table): void {
            $table->string('student_tip', 300)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('technique_assessments', function (Blueprint $table): void {
            $table->dropColumn('student_tip');
        });
    }
};
