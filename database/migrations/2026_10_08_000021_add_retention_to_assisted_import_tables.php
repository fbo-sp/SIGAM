<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('import_batches', 'file_deleted_at')) {
            Schema::table('import_batches', function (Blueprint $table) {
                $table->timestamp('file_deleted_at')->nullable()->after('completed_at');
            });
        }
        if (! Schema::hasColumn('import_rows', 'initial_status')) {
            Schema::table('import_rows', function (Blueprint $table) {
                $table->string('initial_status', 30)->nullable()->after('status')->index();
            });
        }
        DB::table('import_rows')->whereNull('initial_status')->update(['initial_status' => DB::raw('status')]);
        Schema::table('import_rows', function (Blueprint $table) {
            $table->string('initial_status', 30)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('import_rows', 'initial_status')) {
            Schema::table('import_rows', function (Blueprint $table) {
                $table->dropIndex(['initial_status']);
                $table->dropColumn('initial_status');
            });
        }
        if (Schema::hasColumn('import_batches', 'file_deleted_at')) {
            Schema::table('import_batches', function (Blueprint $table) {
                $table->dropColumn('file_deleted_at');
            });
        }
    }
};
