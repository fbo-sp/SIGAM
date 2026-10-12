<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->foreignId('teacher_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('email');
            $table->char('token_hash', 64)->unique();
            $table->text('encrypted_token')->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->string('delivery_status', 30)->default('pending')->index();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->string('error_message', 500)->nullable();
            $table->foreignId('invited_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['association_id', 'teacher_profile_id', 'status'], 'teacher_invitation_profile_status_idx');
            $table->index(['delivery_status', 'scheduled_at', 'attempt_count'], 'teacher_invitation_delivery_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_invitations');
    }
};
