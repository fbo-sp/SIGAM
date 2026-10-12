<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('dojo_id')->nullable()->after('association_id')->constrained()->restrictOnDelete();
            $table->index(['association_id', 'dojo_id', 'status'], 'user_dashboard_scope_idx');
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event_type', 100);
            $table->string('channel', 30);
            $table->string('subject_template', 255);
            $table->text('body_template');
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['association_id', 'event_type', 'channel'], 'notification_template_scope_unique');
            $table->index(['event_type', 'channel', 'status'], 'notification_template_lookup_idx');
        });

        Schema::create('notification_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 100)->index();
            $table->string('idempotency_key', 190);
            $table->json('context');
            $table->dateTime('occurred_at');
            $table->timestamps();
            $table->unique(['association_id', 'idempotency_key'], 'notification_event_idempotency_unique');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_event_id')->constrained()->restrictOnDelete();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->foreignId('person_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 100);
            $table->string('channel', 30);
            $table->string('recipient_email')->nullable();
            $table->string('subject');
            $table->text('body');
            $table->string('status', 30)->index();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->dateTime('scheduled_at')->index();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->unique(['notification_event_id', 'user_id', 'channel'], 'notification_delivery_unique');
            $table->index(['user_id', 'channel', 'read_at', 'created_at'], 'notification_inbox_idx');
            $table->index(['channel', 'status', 'scheduled_at'], 'notification_delivery_queue_idx');
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 100);
            $table->boolean('internal_enabled')->default(true);
            $table->boolean('email_enabled')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_events');
        Schema::dropIfExists('notification_templates');
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('user_dashboard_scope_idx');
            $table->dropConstrainedForeignId('dojo_id');
        });
    }
};
