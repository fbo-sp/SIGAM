<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->foreignId('dojo_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->restrictOnDelete();
            $table->string('sender_level', 20);
            $table->string('target_type', 20);
            $table->json('target');
            $table->string('title', 150);
            $table->text('body');
            $table->boolean('send_email')->default(true);
            $table->unsignedInteger('recipient_count')->default(0);
            $table->string('idempotency_key', 64);
            $table->dateTime('sent_at');
            $table->timestamps();
            $table->unique(['association_id', 'idempotency_key'], 'announcement_idempotency_unique');
            $table->index(['association_id', 'sent_at'], 'announcement_association_sent_idx');
            $table->index(['dojo_id', 'sent_at'], 'announcement_dojo_sent_idx');
        });

        Schema::create('announcement_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->restrictOnDelete();
            $table->foreignId('person_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('notification_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('role', 20);
            $table->string('email')->nullable();
            $table->string('email_status', 20)->default('none');
            $table->unsignedTinyInteger('email_attempts')->default(0);
            $table->dateTime('email_scheduled_at')->nullable();
            $table->dateTime('email_last_attempt_at')->nullable();
            $table->dateTime('email_sent_at')->nullable();
            $table->string('email_error')->nullable();
            $table->timestamps();
            $table->unique(['announcement_id', 'person_id'], 'announcement_recipient_unique');
            $table->index(['email_status', 'email_scheduled_at'], 'announcement_email_queue_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_recipients');
        Schema::dropIfExists('announcements');
    }
};
