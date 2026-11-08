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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            // A document belongs to one client service (for example a Work Permit record), never to the client.
            $table->foreignId('client_service_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->string('storage_key');
            $table->string('mime');
            $table->unsignedInteger('size');
            // The admin's per-document release. It does not depend on the payment status and does not change it.
            $table->boolean('client_can_download')->default(false);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('client_service_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
