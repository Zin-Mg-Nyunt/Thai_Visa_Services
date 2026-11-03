<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('key')->nullable()->unique()->after('parent_id');
        });

        // Catalogues seeded before this column existed: mark the Job Search service.
        DB::table('services')
            ->whereNull('parent_id')
            ->where('title', 'Job Search')
            ->update(['key' => 'job_search']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('key');
        });
    }
};
