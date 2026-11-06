<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('client_service_remarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_service_id')->constrained()->restrictOnDelete();
            $table->string('kind')->default('note');
            // An admin's note. Empty for a refund note, whose numbers are kept in details.
            $table->text('body')->nullable();
            $table->json('details')->nullable();
            // Empty for a note the app wrote, or when the admin who wrote it was deleted.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('client_service_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_service_remarks');
    }
};
