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
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('services')->restrictOnDelete();
            $table->string('title')->nullable();
            $table->string('title_my')->nullable();
            $table->text('public_description')->nullable();
            $table->text('public_description_my')->nullable();
            $table->boolean('show_on_website')->default(false);
            $table->string('status')->default('active');
            $table->decimal('default_fee', 12, 2)->nullable();
            $table->boolean('has_documents')->default(false);
            $table->timestamps();
            $table->softDeletes();

            // Lets client_services point at (service_id, sub_service_id) and have the database check the pair.
            $table->unique(['parent_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
