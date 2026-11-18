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
        Schema::create('job_order_workers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_order_id')->constrained()->restrictOnDelete();
            // The shared person record: the same person may also be a normal client.
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            // The worker's Job Search record: fee, payments and documents live there. One record per worker row.
            $table->foreignId('client_service_id')->constrained()->restrictOnDelete();
            $table->string('case_status')->default('applied');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('client_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_order_workers');
    }
};
