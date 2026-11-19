<?php

use App\Actions\JobSearch\AddWorker;
use App\Actions\Site\SiteSettings;
use App\Models\Client;
use App\Models\ClientPinAttempt;
use App\Models\ClientService;
use App\Models\ClientServiceRemark;
use App\Models\Document;
use App\Models\JobOrder;
use App\Models\Payment;
use App\Models\Service;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Vite;
use Illuminate\Testing\TestResponse;

/**
 * Public data exposure (HLD Section 6): what the public pages (`/`, `/track/{token}`) and the client portal send to a browser. A
 * world full of recognisable values (fees, other people's names, phones and tokens, internal remarks, storage keys, the admin's
 * email, draft texts) is built, and every kind of response is searched for them. A guest gets no fee, no other person's data, no
 * storage key and no private disk path; a client gets only their own details, with no internal field.
 */
beforeEach(function () {
    app()->setLocale('en');
    Vite::useHotFile(sys_get_temp_dir().'/no-such-vite-hot-file-'.uniqid());
    Storage::fake(Document::DISK);
    Storage::fake(SiteSetting::DISK);

    $this->admin = User::factory()->create(['name' => 'Admin Canary Name', 'email' => 'admin-canary@example.test']);
    DB::table('users')->where('id', $this->admin->id)->update(['google_id' => 'google-canary-123456']);

    $this->workPermit = Service::factory()->create(['title' => 'Work Permit', 'default_fee' => '98765.43', 'has_documents' => true, 'show_on_website' => true]);
    $this->hidden = Service::factory()->create(['title' => 'Hidden Service Canary', 'show_on_website' => false]);
    $this->jobSearch = Service::factory()->jobSearch()->create(['title' => 'Job Search', 'show_on_website' => false]);

    // The client who signs in: a Work Permit with money, an internal remark and two documents, a cancelled service with a refund,
    // and a Job Search engagement on an order for a named company.
    $this->alice = Client::factory()->create(['name' => 'Alice Owner', 'phone' => '+66812345678']);
    $this->aliceRecord = ClientService::factory()->forService($this->workPermit)->create(['client_id' => $this->alice->id, 'fee' => '8000.00']);
    Payment::factory()->create(['client_service_id' => $this->aliceRecord->id, 'amount' => '3000.00']);
    ClientServiceRemark::factory()->create(['client_service_id' => $this->aliceRecord->id, 'body' => 'INTERNAL-REMARK-CANARY', 'created_by' => $this->admin->id]);
    $this->openDoc = Document::factory()->create(['client_service_id' => $this->aliceRecord->id, 'title' => 'Permit card', 'storage_key' => 'documents/PRIVATE-KEY-CANARY-1.pdf', 'client_can_download' => true, 'uploaded_by' => $this->admin->id]);
    $this->lockedDoc = Document::factory()->create(['client_service_id' => $this->aliceRecord->id, 'title' => 'Locked card', 'storage_key' => 'documents/PRIVATE-KEY-CANARY-2.pdf', 'uploaded_by' => $this->admin->id]);
    ClientService::factory()->forService($this->workPermit)->cancelled()->create(['client_id' => $this->alice->id, 'fee' => '4321.98', 'cancel_refund_amount' => '1111.11']);
    $this->order = JobOrder::factory()->create(['company_name' => 'COMPANY-CANARY Co']);
    app(AddWorker::class)->handle($this->order, $this->alice->id, [], '12345.67', $this->admin->id);
    ClientPinAttempt::factory()->create(['client_id' => $this->alice->id, 'ip_address' => '203.0.113.77', 'user_agent' => 'UA-CANARY-AGENT']);

    // Other people the visitor must never learn about.
    $this->bob = Client::factory()->create(['name' => 'Bob Other', 'phone' => '+66899990000']);
    $bobRecord = ClientService::factory()->forService($this->workPermit)->create(['client_id' => $this->bob->id, 'fee' => '7777.77']);
    ClientServiceRemark::factory()->create(['client_service_id' => $bobRecord->id, 'body' => 'BOB-REMARK-CANARY']);
    Document::factory()->create(['client_service_id' => $bobRecord->id, 'title' => 'Bob private doc', 'storage_key' => 'documents/BOB-KEY-CANARY.pdf', 'client_can_download' => true]);
    $this->carol = Client::factory()->create(['name' => 'Carol Deleted', 'phone' => '+66877770000']);
    ClientService::factory()->forService($this->workPermit)->create(['client_id' => $this->carol->id, 'fee' => '6666.66']);
    $this->carol->delete();

    // Website settings that are still a draft. The logo's address is public by design (the public bucket, a random name), so its
    // file name is not a secret; only its column name and the draft texts are.
    $setting = SiteSetting::factory()->create(['agency_name' => 'PaingThu', 'hero_description' => 'DRAFT-HERO-CANARY', 'mission' => 'DRAFT-MISSION-CANARY', 'phone' => '+66 DRAFT-PHONE-CANARY', 'email' => 'draft-canary@example.test', 'is_published' => false]);
    $setting->forceFill(['logo_key' => 'site/LOGO-KEY-CANARY.png'])->save();
});

/** What no guest may ever see, in any page or answer. */
function guestForbidden(): array
{
    $t = test();

    return [
        // the admin
        'admin-canary@example.test', 'Admin Canary Name', 'google-canary-123456', 'google_id', 'remember_token', 'password',
        // money
        '98765.43', '8000.00', '3000.00', '5000.00', '4321.98', '7777.77', '6666.66', '12345.67', '1111.11', 'default_fee',
        // people
        'Alice Owner', 'Bob Other', 'Carol Deleted', '+66812345678', '0812345678', '+66899990000', '0899990000', '+66877770000',
        $t->bob->unique_token, $t->carol->unique_token,
        // internal data
        'INTERNAL-REMARK-CANARY', 'BOB-REMARK-CANARY', 'COMPANY-CANARY', '203.0.113.77', 'UA-CANARY-AGENT', 'Hidden Service Canary',
        // files
        'PRIVATE-KEY-CANARY', 'BOB-KEY-CANARY', 'Permit card', 'Locked card', 'Bob private doc', 'storage_key', 'client_can_download', 'uploaded_by',
        // drafts and keys
        'DRAFT-HERO-CANARY', 'DRAFT-MISSION-CANARY', 'DRAFT-PHONE-CANARY', 'draft-canary@example.test', 'logo_key', 'approved_statistics', 'is_published',
        // server paths and secrets
        storage_path(), base_path(), 'private-files', (string) config('app.key'),
    ];
}

/** Every string in `$forbidden` that appears in the answer, HTML or JSON, with entities decoded. */
function leaks(string $content, array $forbidden): array
{
    $content = html_entity_decode($content, ENT_QUOTES);

    return array_values(array_filter($forbidden, fn (string $value): bool => $value !== '' && str_contains($content, $value)));
}

/** The same page as an Inertia (JSON) answer. */
function asInertiaJson(string $url): TestResponse
{
    $version = test()->get($url)->viewData('page')['version'];

    return test()->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'Accept' => 'text/html, application/xhtml+xml']);
}

/** The props of a rendered page. */
function pageProps(TestResponse $response): array
{
    return $response->viewData('page')['props'];
}

describe('the landing page', function () {
    test('a guest gets no fee, no person, no internal data, no storage key and no draft text, in the page or in the JSON answer', function () {
        $html = $this->get(route('home'))->assertOk();
        $json = asInertiaJson(route('home'))->assertOk();

        expect(leaks($html->getContent(), guestForbidden()))->toBe([])->and(leaks($json->getContent(), guestForbidden()))->toBe([]);
    });

    test('it sends exactly the props the page needs and the services have only their public fields', function () {
        $props = pageProps($this->get(route('home'))->assertOk());

        expect(array_keys($props))->toEqualCanonicalizing(['errors', 'name', 'locale', 'auth', 'sidebarOpen', 'statistics', 'services', 'site'])
            ->and($props['auth']['user'])->toBeNull()
            ->and(array_keys($props['services'][0]))->toBe(['id', 'title', 'title_my', 'public_description', 'public_description_my']);
    });

    test('the statistics are only totals, and only the approved ones', function () {
        SiteSetting::query()->update(['approved_statistics' => json_encode(['clients_served'])]);
        Cache::forget(SiteSettings::CACHE_KEY);

        $statistics = pageProps($this->get(route('home'))->assertOk())['statistics'];

        expect(array_keys($statistics))->toBe(['clients_served'])->and($statistics['clients_served'])->toBeInt();
    });

    test('an unpublished website sends no admin text, and the admin\'s name and email never appear even when published', function () {
        $site = pageProps($this->get(route('home')))['site'];

        expect($site['hero_description'])->toBeNull()->and($site['phone'])->toBeNull()->and($site['email'])->toBeNull();

        SiteSetting::query()->update(['is_published' => true]);
        Cache::forget(SiteSettings::CACHE_KEY);
        $published = $this->get(route('home'))->getContent();

        // The published texts are meant to be public; nothing else about the admin is.
        expect(leaks($published, ['admin-canary@example.test', 'Admin Canary Name', 'google-canary-123456', 'updated_by', 'logo_key']))->toBe([]);
    });
});

describe('the tracking page', function () {
    test('a guest sees nothing about the client: no name, no phone, no fee, no other person, no file', function () {
        $url = route('track.show', $this->alice->unique_token);
        $forbidden = array_values(array_filter(guestForbidden(), fn ($value) => $value !== $this->alice->unique_token));

        foreach ([$this->get($url)->assertOk(), asInertiaJson($url)->assertOk()] as $response) {
            expect(leaks($response->getContent(), $forbidden))->toBe([]);
        }
    });

    test('it sends only the token and the lock time', function () {
        $props = pageProps($this->get(route('track.show', $this->alice->unique_token))->assertOk());

        expect(array_keys($props))->toEqualCanonicalizing(['errors', 'name', 'locale', 'auth', 'sidebarOpen', 'token', 'lockedMinutes'])
            ->and($props['token'])->toBe($this->alice->unique_token)->and($props['auth']['user'])->toBeNull();
    });

    test('an unknown link and a deleted person\'s link are plain 404s that say nothing', function () {
        foreach ([route('track.show', 'not-a-real-token'), route('track.show', $this->carol->unique_token)] as $url) {
            $response = $this->get($url)->assertNotFound();

            expect(leaks($response->getContent(), guestForbidden()))->toBe([]);
        }
    });

    test('a wrong PIN tells nothing about the client', function () {
        $response = $this->post(route('track.verify', $this->alice->unique_token), ['pin' => '0000'])->assertSessionHasErrors('pin');
        $forbidden = array_values(array_filter(guestForbidden(), fn ($value) => $value !== $this->alice->unique_token));

        expect(leaks(json_encode(session('errors')->getBag('default')->getMessages()), $forbidden))->toBe([]);
    });
});

describe('the client portal', function () {
    beforeEach(function () {
        $this->post(route('track.verify', $this->alice->unique_token), ['pin' => '5678']);
        $this->profileUrl = route('client.profile', $this->alice->unique_token);
    });

    test('the client sees their own details, and nothing of anyone else or of the agency\'s inside', function () {
        // What the client may see of themselves is allowed; everything else stays forbidden.
        // Her own Work Permit: the fee 8000.00, 3000.00 received, 5000.00 still to pay; her Job Search fee 12345.67. The fee and the refund of
        // her cancelled service (4321.98, 1111.11) stay hidden: a cancelled service shows its status only.
        $allowed = ['Alice Owner', '+66812345678', '8000.00', '3000.00', '5000.00', '12345.67', 'Permit card', 'Locked card', $this->alice->unique_token];
        $forbidden = array_values(array_diff(guestForbidden(), $allowed));

        foreach ([$this->get($this->profileUrl)->assertOk(), asInertiaJson($this->profileUrl)->assertOk()] as $response) {
            expect(leaks($response->getContent(), $forbidden))->toBe([]);
        }
    });

    test('the profile has only the fields a client may see', function () {
        $props = pageProps($this->get($this->profileUrl)->assertOk());

        expect(array_keys($props['profile']['data']))->toBe(['name', 'phone', 'birthday', 'gender', 'photo_url'])
            ->and($props['profile']['data']['name'])->toBe('Alice Owner')->and($props['auth']['user'])->toBeNull();
    });

    test('every service has only public fields, and a document only its id, title and whether it can be downloaded', function () {
        $props = pageProps($this->get($this->profileUrl)->assertOk());
        $allowed = ['id', 'service', 'sub_service', 'status', 'fee', 'received', 'remaining', 'payment_status', 'documents'];

        expect($props['services']['data'])->not->toBeEmpty();
        foreach ($props['services']['data'] as $service) {
            expect(array_diff(array_keys($service), $allowed))->toBe([])->and(array_keys($service['service']))->toBe(['title', 'title_my']);

            foreach ($service['documents'] ?? [] as $document) {
                expect(array_keys($document))->toBe(['id', 'title', 'unlocked']);
            }
        }
    });

    test('a cancelled service shows its status only, and a Job Search service shows no documents', function () {
        $services = collect(pageProps($this->get($this->profileUrl)->assertOk())['services']['data']);

        $cancelled = $services->firstWhere('status', 'cancel');
        $jobSearch = $services->firstWhere('service.title', 'Job Search');

        expect(array_keys($cancelled))->toBe(['id', 'service', 'sub_service', 'status'])
            ->and($jobSearch)->not->toBeNull()->and($jobSearch)->not->toHaveKey('documents');
    });

    test('the other clients\' services never appear', function () {
        $ids = collect(pageProps($this->get($this->profileUrl)->assertOk())['services']['data'])->pluck('id');

        expect($ids->all())->toEqualCanonicalizing(ClientService::query()->where('client_id', $this->alice->id)->pluck('id')->all());
    });

    test('the signed-in client cannot open another person\'s page: they go to that PIN page and learn nothing', function () {
        $response = $this->get(route('client.profile', $this->bob->unique_token))->assertRedirect(route('track.show', $this->bob->unique_token));

        expect(leaks((string) $response->getContent(), array_diff(guestForbidden(), [$this->bob->unique_token])))->toBe([]);
    });

    test('a locked document gives no file, and no file location appears on the page', function () {
        $this->get(route('client.documents.download', [$this->alice->unique_token, $this->lockedDoc->id]))->assertForbidden();

        expect(leaks($this->get($this->profileUrl)->getContent(), ['PRIVATE-KEY-CANARY', 'documents/', 'storage/app', 'private-files']))->toBe([]);
    });
});

describe('closed doors', function () {
    test('a guest gets the PIN page, not the portal, and no data', function () {
        $response = $this->get(route('client.profile', $this->alice->unique_token))->assertRedirect(route('track.show', $this->alice->unique_token));

        expect(leaks((string) $response->getContent(), guestForbidden()))->toBe([]);
    });

    test('a signed-in client has no admin rights: every admin page sends them to the admin login', function () {
        $this->post(route('track.verify', $this->alice->unique_token), ['pin' => '5678']);

        foreach (['/admin/dashboard', '/admin/clients', '/admin/services', '/admin/job-search', '/admin/settings', '/admin/settings/appearance', '/admin/settings/content'] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    });

    test('the private files have no address of their own, and the website images are the only public ones', function () {
        $this->get('/storage/documents/PRIVATE-KEY-CANARY-1.pdf')->assertNotFound();
        $this->get('/private-files/documents/PRIVATE-KEY-CANARY-1.pdf')->assertNotFound();
        expect(config('filesystems.disks.private.root'))->not->toStartWith(public_path())->not->toStartWith(storage_path('app/public'))
            ->and(config('filesystems.disks.private.serve'))->toBeFalse();
    });

    test('a client photo needs a signed link and the right visitor', function () {
        $this->get(route('client-photos.show', $this->alice->id))->assertForbidden();
        $this->get(URL::temporarySignedRoute('client-photos.show', now()->addHour(), ['client' => $this->alice->id]))->assertForbidden();
        $this->get(route('client-photos.show', $this->alice->id).'?signature=forged&expires=9999999999')->assertForbidden();
    });
});
