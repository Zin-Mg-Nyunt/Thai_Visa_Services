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
        Schema::create('job_orders', function (Blueprint $table) {
            $table->id();
            $table->string('company_name');
            // What the company asked for. The workers actually added are counted from job_order_workers.
            $table->unsignedSmallInteger('manpower')->default(0);
            $table->unsignedSmallInteger('male_count')->default(0);
            $table->unsignedSmallInteger('female_count')->default(0);
            $table->date('interview_date')->nullable();
            $table->date('start_date')->nullable();
            // Open, done or cancelled. Done and cancelled both close the order, and a closed order is final.
            $table->string('status')->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('company_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_orders');
    }
};
