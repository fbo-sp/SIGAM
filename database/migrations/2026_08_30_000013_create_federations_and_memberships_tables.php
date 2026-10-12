<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('federations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->string('name', 180);
            $table->string('acronym', 30);
            $table->string('federation_type', 40);
            $table->string('state', 2)->nullable();
            $table->string('country', 2)->default('BR');
            $table->string('email', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('website', 500)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->unique(['association_id', 'acronym']);
            $table->unique(['association_id', 'name']);
        });

        Schema::create('federation_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('federation_id')->constrained()->restrictOnDelete();
            $table->foreignId('martial_art_id')->constrained()->restrictOnDelete();
            $table->string('registration_number', 100);
            $table->unsignedSmallInteger('reference_year');
            $table->date('registration_date');
            $table->date('expiration_date')->nullable();
            $table->foreignId('rank_id')->constrained()->restrictOnDelete();
            $table->decimal('points', 10, 2)->default(0);
            $table->string('status', 20)->default('active')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['student_id', 'federation_id', 'martial_art_id', 'reference_year'], 'federation_membership_period_unique');
            $table->index(['association_id', 'reference_year', 'status'], 'federation_membership_scope_year_idx');
            $table->index(['federation_id', 'expiration_date'], 'federation_membership_expiry_idx');
            $table->index(['student_id', 'reference_year']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('federation_membership_id')->nullable()->after('student_id')->constrained()->restrictOnDelete();
            $table->index(['association_id', 'federation_membership_id'], 'document_federation_membership_idx');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex('document_federation_membership_idx');
            $table->dropConstrainedForeignId('federation_membership_id');
        });
        Schema::dropIfExists('federation_memberships');
        Schema::dropIfExists('federations');
    }
};
