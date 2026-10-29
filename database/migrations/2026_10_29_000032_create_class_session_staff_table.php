<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quem deu a aula e quem auxiliou, informado ao finalizar a chamada (decisão de Fabio em 28/10/2026). Fica fora de
 * class_sessions.actual_teacher_id de propósito: aquele campo muda quem pode abrir a aula no contexto do professor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_session_staff', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('class_session_id')->constrained('class_sessions')->restrictOnDelete();
            $table->foreignId('teacher_profile_id')->constrained('teacher_profiles')->restrictOnDelete();
            $table->string('role', 20);
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['class_session_id', 'teacher_profile_id']);
            $table->index(['teacher_profile_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_session_staff');
    }
};
