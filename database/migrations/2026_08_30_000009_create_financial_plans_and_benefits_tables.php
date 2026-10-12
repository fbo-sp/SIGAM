<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->unique()->constrained()->restrictOnDelete();
            $table->string('nonexistent_day_policy', 30)->default('last_day');
            $table->string('weekend_policy', 30)->default('none');
            $table->boolean('discounts_require_approval')->default(true);
            $table->timestamps();
        });

        Schema::create('financial_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->foreignId('dojo_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('martial_art_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('billing_period', 30);
            $table->decimal('amount', 12, 2);
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['association_id', 'status', 'valid_from']);
        });

        Schema::create('student_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_plan_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->decimal('base_amount', 12, 2);
            $table->unsignedTinyInteger('preferred_due_day');
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->index(['student_id', 'status', 'start_date']);
        });

        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_plan_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('discount_type', 20);
            $table->decimal('discount_value', 12, 2);
            $table->string('reason', 255);
            $table->date('start_date');
            $table->date('end_date');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('requested_at');
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->string('status', 30)->index();
            $table->timestamps();
            $table->index(['student_id', 'status', 'start_date', 'end_date']);
        });

        Schema::create('gratuity_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->foreignId('dojo_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->string('reason', 255);
            $table->text('justification');
            $table->date('start_date');
            $table->date('end_date');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('requested_at');
            $table->foreignId('dojo_approver_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('dojo_decision', 20)->nullable();
            $table->timestamp('dojo_decision_at')->nullable();
            $table->text('dojo_notes')->nullable();
            $table->foreignId('association_approver_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('association_decision', 20)->nullable();
            $table->timestamp('association_decision_at')->nullable();
            $table->text('association_notes')->nullable();
            $table->string('status', 30)->default('awaiting_dojo')->index();
            $table->timestamps();
            $table->index(['association_id', 'status', 'start_date', 'end_date'], 'gratuity_scope_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gratuity_requests');
        Schema::dropIfExists('discounts');
        Schema::dropIfExists('student_plans');
        Schema::dropIfExists('financial_plans');
        Schema::dropIfExists('financial_settings');
    }
};
