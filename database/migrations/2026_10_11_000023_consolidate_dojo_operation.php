<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_plans', function (Blueprint $table) {
            $table->foreignId('dojo_id')->nullable()->after('student_id')->constrained()->restrictOnDelete();
            $table->index(['dojo_id', 'status', 'start_date'], 'student_plans_dojo_status_start_idx');
        });

        Schema::create('championship_dojo_participations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('championship_id')->constrained()->restrictOnDelete();
            $table->foreignId('dojo_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('joined_at');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['championship_id', 'dojo_id'], 'championship_dojo_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('championship_dojo_participations');
        Schema::table('student_plans', function (Blueprint $table) {
            $table->dropIndex('student_plans_dojo_status_start_idx');
            $table->dropConstrainedForeignId('dojo_id');
        });
    }
};
