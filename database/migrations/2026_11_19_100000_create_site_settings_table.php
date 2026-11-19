<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The public website's settings: one row only (its id is always 1, see App\Models\SiteSetting). Every text has an English and a
     * Burmese version (`*_my`); an empty value is simply not shown on the website. Only the storage keys of the three images are
     * kept, never the files or their addresses.
     */
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();

            $table->string('agency_name')->nullable();
            $table->string('agency_name_my')->nullable();
            $table->string('hero_heading')->nullable();
            $table->string('hero_heading_my')->nullable();
            $table->text('hero_description')->nullable();
            $table->text('hero_description_my')->nullable();

            // The About section: an introduction and the three cards (Experience, Mission, How we help).
            $table->text('overview')->nullable();
            $table->text('overview_my')->nullable();
            $table->text('experience')->nullable();
            $table->text('experience_my')->nullable();
            $table->text('mission')->nullable();
            $table->text('mission_my')->nullable();
            $table->text('how_we_help')->nullable();
            $table->text('how_we_help_my')->nullable();

            // Nullable here; the logo and the favicon are required only to publish the website.
            $table->string('logo_key')->nullable();
            $table->string('favicon_key')->nullable();
            $table->string('hero_image_key')->nullable();

            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('address', 500)->nullable();
            $table->string('address_my', 500)->nullable();
            $table->string('messaging_link', 2048)->nullable();
            $table->json('social_links')->nullable();

            // Which public numbers the admin has approved for display.
            $table->json('approved_statistics')->nullable();

            $table->boolean('is_published')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
