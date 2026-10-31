<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * MySQL has no partial unique index, so uniqueness among active clients is enforced on a
     * generated column that holds the phone number only while the client is not deleted.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->index('phone');
            $table->string('active_phone')
                ->nullable()
                ->virtualAs('case when deleted_at is null then phone end')
                ->unique();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Restoring the plain unique index fails when a deleted and an active client share a phone number.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['active_phone']);
            $table->dropColumn('active_phone');
            $table->dropIndex(['phone']);
            $table->unique('phone');
        });
    }
};
