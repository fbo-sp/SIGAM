<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_session_visitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->foreignId('host_dojo_id')->constrained('dojos')->restrictOnDelete();
            $table->foreignId('class_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->string('status', 30)->default('authorized')->index();
            $table->foreignId('authorized_by_user_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('authorized_at');
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['class_session_id', 'student_id'], 'class_session_visitor_unique');
            $table->index(['association_id', 'host_dojo_id', 'status'], 'session_visitor_scope_idx');
        });

        Schema::table('attendance', function (Blueprint $table) {
            $table->string('participation_type', 20)->default('regular')->after('student_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropIndex(['participation_type']);
            $table->dropColumn('participation_type');
        });
        Schema::dropIfExists('class_session_visitors');
    }
};
