<?php

namespace Database\Seeders;

use App\Enums\ServiceIcon;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\Document;
use App\Models\JobOrder;
use App\Models\Payment;
use App\Models\Service;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The data the browser tests (tests/Browser, Playwright) run against. It refuses to run anywhere but the throw-away database of the
 * browser tests: the `browser` environment AND a SQLite file called browser.sqlite. So it can never touch the development or the
 * MySQL database, whatever is typed.
 *
 * Seeded: one admin, three main services (Work Permit and Passport with their icons, Job Search) and one sub-service of Passport, a client with a Work Permit and documents (PIN 5678), an open job order, and a published
 * website with content, a logo and a favicon.
 */
class BrowserTestSeeder extends Seeder
{
    public const ADMIN_EMAIL = 'admin@browser.test';

    public const ADMIN_PASSWORD = 'browser-test-password';

    public const CLIENT_PHONE = '+66812345678';

    public function run(): void
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! app()->environment('browser') || config('database.default') !== 'sqlite' || basename($database) !== 'browser.sqlite') {
            throw new RuntimeException('The browser test seeder only runs on the browser tests\' own database (environment "browser", SQLite file browser.sqlite).');
        }

        User::query()->forceCreate([
            'name' => 'Browser Admin', 'email' => self::ADMIN_EMAIL, 'password' => Hash::make(self::ADMIN_PASSWORD),
            'role' => UserRole::Superadmin, 'email_verified_at' => now(),
        ]);

        $workPermit = Service::factory()->create(['title' => 'Work Permit', 'title_my' => 'အလုပ်လုပ်ခွင့်', 'public_description' => 'Work permit applications and renewals.', 'has_documents' => true, 'show_on_website' => true, 'icon' => ServiceIcon::FileCheck->value]);
        $passport = Service::factory()->create(['title' => 'Passport', 'public_description' => 'Passport services.', 'show_on_website' => true, 'icon' => ServiceIcon::BookUser->value]);
        Service::factory()->jobSearch()->create(['show_on_website' => false]);
        // A sub-service with an icon of its own: it must never become a card on the landing page.
        Service::factory()->subServiceOf($passport)->create(['title' => 'MOU', 'public_description' => null, 'icon' => ServiceIcon::Stamp->value]);

        $client = Client::factory()->create(['name' => 'Alice Browser', 'phone' => self::CLIENT_PHONE, 'unique_token' => 'browser-test-token-'.str_repeat('a', 29)]);
        $record = ClientService::factory()->forService($workPermit)->create(['client_id' => $client->id, 'fee' => '8000.00']);
        Payment::factory()->create(['client_service_id' => $record->id, 'amount' => '3000.00']);
        Document::factory()->create(['client_service_id' => $record->id, 'title' => 'Open permit card', 'client_can_download' => true]);
        Document::factory()->create(['client_service_id' => $record->id, 'title' => 'Locked permit card', 'client_can_download' => false]);

        JobOrder::factory()->create(['company_name' => 'Browser Factory Co', 'manpower' => 5, 'male_count' => 3, 'female_count' => 2]);

        // A logo and a favicon (real tiny images on the website images disk), so the website can be shown as published.
        $disk = Storage::disk(SiteSetting::DISK);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $disk->put('site/browser-seed-logo.png', $png);
        $disk->put('site/browser-seed-favicon.png', $png);

        DB::transaction(function (): void {
            $setting = SiteSetting::factory()->create([
                'agency_name' => 'Browser Agency', 'hero_heading' => 'Visas made simple', 'hero_description' => 'We help with visas and work permits in Thailand.',
                'overview' => 'A small agency that looks after your paperwork.', 'experience' => 'Experience written by the admin.', 'mission' => 'Make paperwork easy.', 'how_we_help' => 'By phone, by message and in person.',
                'address' => '1 Test Road, Bangkok', 'phone' => '+66 2 123 4567', 'email' => 'hello@browser.test', 'messaging_link' => 'https://line.me/ti/p/browser',
                'social_links' => ['facebook' => 'https://facebook.com/browser'], 'approved_statistics' => ['clients_served', 'services_provided'], 'is_published' => true,
            ]);
            $setting->forceFill(['logo_key' => 'site/browser-seed-logo.png', 'favicon_key' => 'site/browser-seed-favicon.png'])->save();
        });
    }
}
