<?php

use App\Models\Client;
use App\Models\ClientService;
use App\Models\Document;
use App\Models\Service;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

/**
 * Public protections (HLD Sections 4.10 and 6): nosniff on every response, noindex and no referrer on the pages that belong to
 * one client, and a rate limit on the landing page. See App\Http\Middleware\SecurityHeaders.
 */
beforeEach(function () {
    RateLimiter::clear('throttle');
    Storage::fake(Document::DISK);

    $this->client = Client::factory()->create(['phone' => '+66812345678']);
    $this->record = ClientService::factory()->forService(Service::factory()->create(['has_documents' => true]))
        ->create(['client_id' => $this->client->id]);
});

/** Puts the client's session in place, as the PIN page does. */
function headersTestSignIn(Client $client): void
{
    test()->post(route('track.verify', $client->unique_token), ['pin' => '5678']);
}

test('every kind of response carries nosniff: public, tracking, portal, admin login, an unknown page and the health check', function () {
    headersTestSignIn($this->client);

    foreach ([
        route('home'),
        route('track.show', $this->client->unique_token),
        route('client.profile', $this->client->unique_token),
        route('login'),
        '/no-such-page',
        '/up',
    ] as $url) {
        expect($this->get($url)->headers->get('X-Content-Type-Options'))->toBe('nosniff', $url);
    }

    // A response to an Inertia request (JSON) and a redirect carry it too.
    $this->get(route('home'), ['X-Inertia' => 'true'])->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get('/admin/dashboard')->assertRedirect()->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('a file download carries nosniff', function () {
    headersTestSignIn($this->client);
    $document = Document::factory()->create(['client_service_id' => $this->record->id, 'client_can_download' => true, 'storage_key' => 'documents/a.pdf']);
    Storage::disk(Document::DISK)->put($document->storage_key, '%PDF-1.4');

    $this->get(route('client.documents.download', [$this->client->unique_token, $document->id]))
        ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('the tracking page and the client portal are noindex with no referrer, in the header and in the page', function () {
    $urls = [route('track.show', $this->client->unique_token)];
    $responses = [$this->get($urls[0])->assertOk()];

    headersTestSignIn($this->client);
    $urls[] = route('client.profile', $this->client->unique_token);
    $responses[] = $this->get($urls[1])->assertOk();

    foreach ($responses as $index => $response) {
        expect($response->headers->get('X-Robots-Tag'))->toBe('noindex', $urls[$index])
            ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer', $urls[$index])
            ->and($response->getContent())->toContain('<meta name="robots" content="noindex">');
    }
});

test('the PIN check and an unknown tracking link are noindex too', function () {
    $this->post(route('track.verify', $this->client->unique_token), ['pin' => '0000'])
        ->assertHeader('X-Robots-Tag', 'noindex')->assertHeader('Referrer-Policy', 'no-referrer');
    $this->get('/track/not-a-real-token')->assertNotFound()
        ->assertHeader('X-Robots-Tag', 'noindex')->assertHeader('Referrer-Policy', 'no-referrer');
});

test('the landing page, the admin login and the admin pages are not marked noindex and get no referrer policy', function () {
    foreach ([route('home'), route('login')] as $url) {
        $response = $this->get($url)->assertOk();

        $response->assertHeaderMissing('X-Robots-Tag')->assertHeaderMissing('Referrer-Policy');
        expect($response->getContent())->not->toContain('name="robots"');
    }
});

test('the landing page returns 429 after 60 visits a minute, and visitors are counted separately', function () {
    foreach (range(1, 60) as $unused) {
        $this->get(route('home'))->assertOk();
    }

    $this->get(route('home'))->assertStatus(429)->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get(route('home'))->assertOk();
});
