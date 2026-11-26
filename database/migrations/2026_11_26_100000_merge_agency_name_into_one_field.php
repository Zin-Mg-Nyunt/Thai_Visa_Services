<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The agency name becomes one name for every language: it is the name shown beside the logo everywhere. A Burmese name is kept
     * by moving it into `agency_name` when that one is empty, then the Burmese column is dropped.
     */
    public function up(): void
    {
        DB::table('site_settings')
            ->where(fn ($query) => $query->whereNull('agency_name')->orWhereRaw("TRIM(agency_name) = ''"))
            ->whereNotNull('agency_name_my')
            ->whereRaw("TRIM(agency_name_my) <> ''")
            ->update(['agency_name' => DB::raw('agency_name_my')]);

        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn('agency_name_my');
        });
    }

    /**
     * Reverse the migrations. The Burmese column comes back empty; the name stays in `agency_name`.
     */
    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->string('agency_name_my')->nullable()->after('agency_name');
        });
    }
};
