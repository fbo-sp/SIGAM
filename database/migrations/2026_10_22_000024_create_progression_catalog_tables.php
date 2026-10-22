<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technique_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->foreignId('martial_art_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('technique_items')->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('knowledge_type', 20);
            $table->string('name', 160);
            $table->string('original_name', 160)->nullable();
            $table->text('description')->nullable();
            $table->string('video_url', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->index(['association_id', 'martial_art_id', 'parent_id'], 'technique_items_tree_index');
        });

        Schema::create('rank_curriculum_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->foreignId('rank_id')->constrained()->restrictOnDelete();
            $table->foreignId('technique_item_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('required_level')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['association_id', 'rank_id', 'technique_item_id'], 'rank_curriculum_entry_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_curriculum_entries');
        Schema::dropIfExists('technique_items');
    }
};
