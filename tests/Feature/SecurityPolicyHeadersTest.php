<?php

use App\Models\Client;
use App\Models\ClientService;
use App\Models\Document;
use App\Models\Service;
use App\Support\ContentSecurityPolicy;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Vite;

/**
 * The remaining security headers (Sections 4.10 and 6): X-Frame-Options, Strict-Transport-Security in production over https only,
 * and the Content Security Policy, sent as Report-Only until CSP_ENFORCE is on. See App\Http\Middleware\SecurityHeaders and
 * App\Support\ContentSecurityPolicy.
 */
beforeEach(function () {
    // No Vite dev server for these tests, whatever is running on this machine.
    Vite::useHotFile(sys_get_temp_dir().'/no-such-vite-hot-file-'.uniqid());
    config(['security.csp.enforce' => false, 'filesystems.disks.public_assets.url' => 'http://localhost/storage']);
});

/** The directive's sources, as a list. */
function directive(string $policy, string $name): array
{
    foreach (explode('; ', $policy) as $part) {
        if (str_starts_with($part, $name.' ')) {
            return explode(' ', substr($part, strlen($name) + 1));
        }
    }

    return [];
}

describe('frame protection', function () {
    test('every response says no other site may frame it', function () {
        $client = Client::factory()->create();

        foreach ([route('home'), route('login'), route('track.show', $client->unique_token), '/no-such-page', '/up'] as $url) {
            expect($this->get($url)->headers->get('X-Frame-Options'))->toBe('DENY', $url);
        }
    });
});

describe('HSTS', function () {
    test('it is not sent in development, over http', function () {
        $this->get(route('home'))->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/')->assertHeaderMissing('Strict-Transport-Security');
    });

    test('in production it is sent over https only, for six months, with no includeSubDomains and no preload', function () {
        app()->detectEnvironment(fn () => 'production');

        $secure = $this->get('https://localhost/')->headers->get('Strict-Transport-Security');

        expect($secure)->toBe('max-age=15552000')->not->toContain('includeSubDomains')->not->toContain('preload');
        $this->get('http://localhost/')->assertHeaderMissing('Strict-Transport-Security');
    });

    test('it also covers the pages that are not found and the error responses in production', function () {
        app()->detectEnvironment(fn () => 'production');

        $this->get('https://localhost/no-such-page')->assertNotFound()->assertHeader('Strict-Transport-Security', 'max-age=15552000');
    });
});

describe('the Content Security Policy', function () {
    test('it is sent as Report-Only on every HTML page, and blocks nothing', function () {
        $client = Client::factory()->create();

        foreach ([route('home'), route('login'), route('track.show', $client->unique_token)] as $url) {
            $response = $this->get($url)->assertOk();

            expect($response->headers->get('Content-Security-Policy-Report-Only'))->toStartWith("default-src 'self'")->and($response->headers->has('Content-Security-Policy'))->toBeFalse();
        }
    });

    test('the client portal has it too', function () {
        $client = Client::factory()->create(['phone' => '+66812345678']);
        $this->post(route('track.verify', $client->unique_token), ['pin' => '5678']);

        expect($this->get(route('client.profile', $client->unique_token))->assertOk()->headers->get('Content-Security-Policy-Report-Only'))->toStartWith("default-src 'self'");
    });

    test('turning CSP_ENFORCE on sends the enforcing header instead', function () {
        config(['security.csp.enforce' => true]);

        $response = $this->get(route('home'))->assertOk();

        expect($response->headers->get('Content-Security-Policy'))->toStartWith("default-src 'self'")->and($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();
    });

    test('it is not sent on a file download or a JSON answer', function () {
        Storage::fake(Document::DISK);
        $client = Client::factory()->create(['phone' => '+66812345678']);
        $record = ClientService::factory()->forService(Service::factory()->create(['has_documents' => true]))->create(['client_id' => $client->id]);
        $document = Document::factory()->create(['client_service_id' => $record->id, 'client_can_download' => true, 'storage_key' => 'documents/a.pdf']);
        Storage::disk(Document::DISK)->put($document->storage_key, '%PDF-1.4');
        $this->post(route('track.verify', $client->unique_token), ['pin' => '5678']);

        $download = $this->get(route('client.documents.download', [$client->unique_token, $document->id]))->assertOk();
        $json = $this->postJson(route('track.verify', $client->unique_token), []);

        expect($json->headers->get('Content-Type'))->toContain('application/json')
            ->and($download->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse()->and($json->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();
    });

    test('scripts come only from the app: no inline script, no eval, no other site', function () {
        $policy = $this->get(route('home'))->headers->get('Content-Security-Policy-Report-Only');

        expect(directive($policy, 'script-src'))->toBe(["'self'"])->and($policy)->not->toContain('unsafe-eval')->not->toContain('*');
        expect(directive($policy, 'style-src'))->toBe(["'self'", "'unsafe-inline'"]);
        expect(directive($policy, 'font-src'))->toBe(["'self'", 'data:']);
    });

    test('it has no plug-ins, a fixed base address, forms to the app only and no framing', function () {
        $policy = $this->get(route('home'))->headers->get('Content-Security-Policy-Report-Only');

        expect(directive($policy, 'object-src'))->toBe(["'none'"])->and(directive($policy, 'base-uri'))->toBe(["'self'"])
            ->and(directive($policy, 'form-action'))->toBe(["'self'"])->and(directive($policy, 'frame-ancestors'))->toBe(["'none'"])
            ->and(directive($policy, 'connect-src'))->toBe(["'self'"]);
    });

    test('images may come from the app, data and blob addresses, and the public Spaces bucket when there is one', function () {
        config(['filesystems.disks.public_assets.url' => 'https://assets.example.test/path/to/bucket']);

        $policy = $this->get(route('home'))->headers->get('Content-Security-Policy-Report-Only');

        expect(directive($policy, 'img-src'))->toBe(["'self'", 'data:', 'blob:', 'https://assets.example.test']);
    });

    test('a website image address that is a path on this site adds no other origin', function () {
        config(['filesystems.disks.public_assets.url' => '/storage']);

        expect(directive($this->get(route('home'))->headers->get('Content-Security-Policy-Report-Only'), 'img-src'))->toBe(["'self'", 'data:', 'blob:']);
    });

    test('while the Vite dev server runs, its address and websocket are allowed, in development only', function () {
        $hot = sys_get_temp_dir().'/vite-hot-'.uniqid();
        file_put_contents($hot, 'http://127.0.0.1:5173');
        Vite::useHotFile($hot);

        $policy = $this->get(route('home'))->headers->get('Content-Security-Policy-Report-Only');

        expect(directive($policy, 'script-src'))->toContain('http://127.0.0.1:5173')->and(directive($policy, 'connect-src'))->toContain('ws://127.0.0.1:5173')
            ->and(directive($policy, 'img-src'))->toContain('http://127.0.0.1:5173');

        // A leftover hot file never opens the policy in production.
        app()->detectEnvironment(fn () => 'production');
        $production = $this->get('https://localhost/')->headers->get('Content-Security-Policy-Report-Only');

        expect($production)->not->toContain('5173');
        @unlink($hot);
    });

    test('the pages use no inline script, so the strict script policy does not break them', function () {
        $html = $this->get(route('home'))->getContent();

        preg_match_all('#<script\b([^>]*)>#i', $html, $scripts);

        expect($scripts[1])->not->toBeEmpty();
        foreach ($scripts[1] as $attributes) {
            expect(str_contains($attributes, 'src=') || str_contains($attributes, 'type="application/json"'))->toBeTrue($attributes);
        }
        expect(file_get_contents(resource_path('views/app.blade.php')))->not->toMatch('#<script(?![^>]*src)#');
    });

    test('the fonts and the stylesheet are the app\'s own files, with no font service or other site', function () {
        $css = file_get_contents(resource_path('css/app.css'));

        expect($css)->not->toContain('fonts.googleapis')->not->toContain('fonts.gstatic')->not->toMatch('#@import\s+url\(\s*[\'"]?https?:#')
            ->and(file_get_contents(resource_path('views/app.blade.php')))->not->toContain('fonts.googleapis')->not->toContain('<link rel="stylesheet" href="http');
    });

    test('the policy builder gives the same string for the same input and reads the origin of an address', function () {
        expect(ContentSecurityPolicy::header())->toBe(ContentSecurityPolicy::header())
            ->and(ContentSecurityPolicy::origin('https://a.example.test:8443/x/y?z=1'))->toBe('https://a.example.test:8443')
            ->and(ContentSecurityPolicy::origin('/storage'))->toBeNull()->and(ContentSecurityPolicy::origin(''))->toBeNull()
            ->and(ContentSecurityPolicy::origin('javascript:alert(1)'))->toBeNull();
    });
});

test('the earlier headers are unchanged: nosniff everywhere, noindex and no referrer on the client pages', function () {
    $client = Client::factory()->create();

    $response = $this->get(route('track.show', $client->unique_token))->assertOk();

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')->and($response->headers->get('X-Robots-Tag'))->toBe('noindex')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer');
});
