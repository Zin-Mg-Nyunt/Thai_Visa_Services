<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One Job Search record (an engagement) can now hold several applications over time, so a record is no longer
     * unique per worker row. The foreign key needs an index on client_service_id at all times, so the plain index is
     * created before the unique one is dropped.
     *
     * The old "working" status is not a status any more. Nothing in the app can set it, but if a row has it the
     * migration stops instead of guessing what it should become.
     */
    public function up(): void
    {
        $working = DB::table('job_order_workers')->where('case_status', 'working')->count();

        if ($working > 0) {
            throw new RuntimeException("{$working} job_order_workers row(s) still have the removed case status \"working\". Decide what they become, then update them before migrating.");
        }

        Schema::table('job_order_workers', function (Blueprint $table) {
            $table->index('client_service_id');
        });

        Schema::table('job_order_workers', function (Blueprint $table) {
            $table->dropUnique(['client_service_id']);
        });
    }

    /**
     * Restoring the unique key fails when an engagement already has more than one application.
     */
    public function down(): void
    {
        Schema::table('job_order_workers', function (Blueprint $table) {
            $table->unique('client_service_id');
        });

        Schema::table('job_order_workers', function (Blueprint $table) {
            $table->dropIndex(['client_service_id']);
        });
    }
};
