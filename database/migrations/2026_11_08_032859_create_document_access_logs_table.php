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
        Schema::create('document_access_logs', function (Blueprint $table) {
            $table->id();
            // The log outlives the document: deleting a document keeps its rows, with no document id.
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            // Copies, so the history stays readable after the document is deleted (or the client is).
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('document_title')->nullable();
            // Every attempt is logged: allowed, or denied with the reason (locked, not_owner).
            $table->string('outcome');
            $table->string('reason')->nullable();
            $table->string('actor_type');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('ip', 45)->nullable();
            // The time the attempt was handled. For a download from Spaces this is when the link was issued.
            $table->timestamp('created_at')->useCurrent();

            $table->index('document_id');
            $table->index(['client_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_access_logs');
    }
};
