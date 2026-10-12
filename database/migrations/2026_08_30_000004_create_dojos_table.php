<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dojos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('postal_code', 8)->nullable();
            $table->string('street')->nullable();
            $table->string('number', 30)->nullable();
            $table->string('complement')->nullable();
            $table->string('district')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 2)->nullable();
            $table->string('country', 2)->default('BR');
            $table->unsignedInteger('capacity')->nullable();
            $table->date('started_at')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['association_id', 'code']);
            $table->index(['association_id', 'name']);
            $table->index(['association_id', 'status']);
        });

        Schema::table('association_person_roles', function (Blueprint $table) {
            $table->index(['association_id', 'person_id', 'status'], 'association_person_active_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('association_person_roles', function (Blueprint $table) {
            $table->dropIndex('association_person_active_lookup');
        });

        Schema::dropIfExists('dojos');
    }
};
