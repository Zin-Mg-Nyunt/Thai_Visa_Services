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
        Schema::create('client_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            // Always the main service; the optional sub-service is checked against it below.
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $table->foreignId('sub_service_id')->nullable()->constrained('services')->restrictOnDelete();
            $table->string('status')->default('in_progress');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('fee', 12, 2)->default(0);
            // Refund: set from fee changes. Cancel refund: entered when the service is cancelled. Kept = received minus both.
            $table->decimal('refund_amount', 12, 2)->default(0);
            $table->decimal('cancel_refund_amount', 12, 2)->default(0);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            // The database checks that a chosen sub-service belongs to the service. A NULL sub-service skips the check.
            $table->foreign(['service_id', 'sub_service_id'])
                ->references(['parent_id', 'id'])
                ->on('services')
                ->restrictOnDelete();

            $table->index(['client_id', 'service_id', 'status']);
            $table->index(['service_id', 'sub_service_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_services');
    }
};
