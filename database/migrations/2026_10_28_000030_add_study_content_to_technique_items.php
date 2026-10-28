<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Área de estudo do portal (decisões de 28/10/2026): conteúdo de estudo no item do catálogo e registro
 * "Estudei esta técnica" do aluno, um por dia, que o professor vê na ficha de acompanhamento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technique_items', function (Blueprint $table): void {
            $table->string('translation', 160)->nullable()->after('original_name');
            $table->text('key_points')->nullable()->after('description');
            $table->string('common_error', 300)->nullable()->after('key_points');
            $table->text('steps')->nullable()->after('common_error');
            $table->string('sheet_path', 255)->nullable()->after('video_url');
            $table->string('sheet_mime', 60)->nullable()->after('sheet_path');
            $table->string('sheet_name', 160)->nullable()->after('sheet_mime');
        });

        Schema::create('technique_study_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('technique_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('studied_on');
            $table->timestamps();
            $table->unique(['student_id', 'technique_item_id', 'studied_on'], 'technique_study_log_day_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technique_study_logs');
        Schema::table('technique_items', function (Blueprint $table): void {
            $table->dropColumn(['translation', 'key_points', 'common_error', 'steps', 'sheet_path', 'sheet_mime', 'sheet_name']);
        });
    }
};
