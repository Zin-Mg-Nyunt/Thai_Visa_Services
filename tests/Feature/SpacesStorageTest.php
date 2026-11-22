<?php

use App\Models\Client;
use App\Models\ClientService;
use App\Models\Document;
use App\Models\Service;
use App\Models\SiteSetting;
use App\Models\User;
use Aws\CommandInterface;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Psr\Http\Message\RequestInterface;

/**
 * The storage switch: STORAGE_DRIVER=local keeps today's local disks, STORAGE_DRIVER=s3 puts the same two disks on DigitalOcean
 * Spaces, each with its own bucket and its own access key, with no change to the application code.
 *
 * Everything here is offline. No test reaches Spaces: a signed link and a public address are worked out locally by the AWS SDK,
 * and every request a Spaces disk would send is answered by an in-memory handler that records it. The credentials are made up.
 */
const SPACES_PRIVATE_BUCKET = 'paingthu-visa-service-private';
const SPACES_PUBLIC_BUCKET = 'paingthu-visa-service-public';
const SPACES_PUBLIC_URL = 'https://paingthu-visa-service-public.sgp1.digitaloceanspaces.com';

/** @return array<string, string|null> the environment of production on Spaces, with made-up credentials */
function spacesEnvironment(): array
{
    return [
        'STORAGE_DRIVER' => 's3',
        'DO_SPACES_REGION' => 'sgp1',
        'DO_SPACES_ENDPOINT' => 'https://sgp1.digitaloceanspaces.com',
        'DO_SPACES_PRIVATE_BUCKET' => SPACES_PRIVATE_BUCKET,
        'DO_SPACES_PRIVATE_KEY' => 'PRIVATEKEYID',
        'DO_SPACES_PRIVATE_SECRET' => 'private-secret',
        'DO_SPACES_PUBLIC_BUCKET' => SPACES_PUBLIC_BUCKET,
        'DO_SPACES_PUBLIC_KEY' => 'PUBLICKEYID',
        'DO_SPACES_PUBLIC_SECRET' => 'public-secret',
        'PUBLIC_ASSETS_URL' => SPACES_PUBLIC_URL,
        'PUBLIC_ASSETS_ROOT' => null,
    ];
}

/** @return array<string, string|null> the local environment: no Spaces value at all */
function localStorageEnvironment(): array
{
    return array_map(fn () => null, spacesEnvironment()) + ['STORAGE_DRIVER' => 'local'];
}

/**
 * The disks of config/filesystems.php as they are when the file is read with the given environment. The values are put straight
 * into $_SERVER, $_ENV and putenv() (the places env() reads), because the env repository will not overwrite the values that
 * phpunit.xml forces; they are put back afterwards.
 *
 * @param  array<string, string|null>  $environment
 * @return array<string, array<string, mixed>>
 */
function storageDisksWith(array $environment): array
{
    $saved = [];

    foreach ($environment as $name => $value) {
        $saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
        spacesSetVariable($name, $value, $value, $value === null ? false : $value);
    }

    try {
        return (require config_path('filesystems.php'))['disks'];
    } finally {
        foreach ($saved as $name => [$server, $env, $putenv]) {
            spacesSetVariable($name, $server, $env, $putenv);
        }
    }
}

function spacesSetVariable(string $name, ?string $server, ?string $env, string|false $putenv): void
{
    if ($server === null) {
        unset($_SERVER[$name]);
    } else {
        $_SERVER[$name] = $server;
    }

    if ($env === null) {
        unset($_ENV[$name]);
    } else {
        $_ENV[$name] = $env;
    }

    putenv($putenv === false ? $name : "{$name}={$putenv}");
}

/**
 * Put the two app disks on Spaces for this test, built by Laravel from the config as in production, with every request answered
 * in memory. Returns the list the requests are recorded in: [operation, bucket, key, ACL].
 *
 * @param  array<string, string>  $objects  key => content of the objects that already exist
 * @return ArrayObject<int, array{0: string, 1: string, 2: string|null, 3: string|null}>
 */
function useOfflineSpaces(array $objects = []): ArrayObject
{
    $requests = new ArrayObject;
    $disks = storageDisksWith(spacesEnvironment());

    $handler = function (CommandInterface $command, RequestInterface $request) use ($requests, &$objects) {
        $key = $command['Key'] ?? null;
        $requests[] = [$command->getName(), $command['Bucket'], $key, $command['ACL'] ?? null];

        if ($command->getName() === 'PutObject') {
            $objects[$key] = (string) $command['Body'];
        }

        return Create::promiseFor(new Result(match ($command->getName()) {
            'HeadObject' => ['ContentLength' => strlen($objects[$key] ?? ''), 'ContentType' => 'application/octet-stream'],
            'GetObject' => ['Body' => Utils::streamFor($objects[$key] ?? '')],
            default => [],
        }));
    };

    foreach (['private', 'public_assets'] as $disk) {
        config(["filesystems.disks.{$disk}" => $disks[$disk] + ['handler' => $handler]]);
        Storage::forgetDisk($disk);
    }

    return $requests;
}

/** A real file with this content, so its type is read from the content as in production. */
function spacesUpload(string $content, string $name): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'spaces');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

function spacesPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
}

function spacesJpeg(): string
{
    return base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
}

describe('local storage (STORAGE_DRIVER=local)', function () {
    test('the disks keep their local roots and addresses, as before', function () {
        $disks = storageDisksWith(localStorageEnvironment());

        expect($disks['private']['driver'])->toBe('local')
            ->and($disks['private']['root'])->toBe(storage_path('app/private'))
            ->and($disks['private']['serve'])->toBeFalse()
            ->and($disks['public_assets']['driver'])->toBe('local')
            ->and($disks['public_assets']['root'])->toBe(storage_path('app/public'))
            ->and($disks['public_assets']['url'])->toBe(rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage');
    });

    test('PUBLIC_ASSETS_ROOT still moves the local website images folder', function () {
        $disks = storageDisksWith(['PUBLIC_ASSETS_ROOT' => '/tmp/browser-assets'] + localStorageEnvironment());

        expect($disks['public_assets']['root'])->toBe('/tmp/browser-assets');
    });

    test('the local disks work with no Spaces credentials at all', function () {
        $disks = storageDisksWith(localStorageEnvironment());

        expect($disks['private']['key'])->toBeNull()
            ->and(Storage::build($disks['private']))->toBeInstanceOf(LocalFilesystemAdapter::class)
            ->and(Storage::build($disks['public_assets']))->toBeInstanceOf(LocalFilesystemAdapter::class);
    });

    test('the test suite itself always runs on the local disks', function () {
        expect(config('filesystems.disks.private.driver'))->toBe('local')
            ->and(config('filesystems.disks.public_assets.driver'))->toBe('local');
    });
});

describe('Spaces configuration (STORAGE_DRIVER=s3)', function () {
    test('both disks use the S3 driver with an empty root, so no local path becomes part of a key', function () {
        $disks = storageDisksWith(spacesEnvironment());

        expect($disks['private']['driver'])->toBe('s3')
            ->and($disks['private']['root'])->toBe('')
            ->and($disks['public_assets']['driver'])->toBe('s3')
            ->and($disks['public_assets']['root'])->toBe('');
    });

    test('a PUBLIC_ASSETS_ROOT left in the environment is ignored on Spaces', function () {
        $disks = storageDisksWith(['PUBLIC_ASSETS_ROOT' => '/srv/assets'] + spacesEnvironment());

        expect($disks['public_assets']['root'])->toBe('');
    });

    test('each disk has its own bucket and its own credentials, in the Singapore region', function () {
        $disks = storageDisksWith(spacesEnvironment());

        expect($disks['private'])->toMatchArray([
            'bucket' => SPACES_PRIVATE_BUCKET, 'key' => 'PRIVATEKEYID', 'secret' => 'private-secret', 'visibility' => 'private',
            'region' => 'sgp1', 'endpoint' => 'https://sgp1.digitaloceanspaces.com', 'use_path_style_endpoint' => false,
        ])->and($disks['public_assets'])->toMatchArray([
            'bucket' => SPACES_PUBLIC_BUCKET, 'key' => 'PUBLICKEYID', 'secret' => 'public-secret', 'visibility' => 'public',
            'region' => 'sgp1', 'endpoint' => 'https://sgp1.digitaloceanspaces.com', 'url' => SPACES_PUBLIC_URL,
        ]);
    });

    test('Laravel builds both disks as S3 disks from the config alone', function () {
        useOfflineSpaces();

        expect(Storage::disk(Document::DISK))->toBeInstanceOf(AwsS3V3Adapter::class)
            ->and(Storage::disk(SiteSetting::DISK))->toBeInstanceOf(AwsS3V3Adapter::class);
    });

    test('a website image address is the public assets URL plus the key', function () {
        useOfflineSpaces();

        expect(Storage::disk(SiteSetting::DISK)->url('site/logo.png'))->toBe(SPACES_PUBLIC_URL.'/site/logo.png');
    });

    test('a private link is signed by the private bucket\'s key, for that bucket, and lasts the document link time', function () {
        useOfflineSpaces();

        $url = Storage::disk(Document::DISK)->temporaryUrl('documents/a.pdf', now()->addMinutes(Document::SIGNED_URL_MINUTES));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        expect(parse_url($url, PHP_URL_HOST))->toBe(SPACES_PRIVATE_BUCKET.'.sgp1.digitaloceanspaces.com')
            ->and(parse_url($url, PHP_URL_PATH))->toBe('/documents/a.pdf')
            ->and($query['X-Amz-Expires'])->toBe((string) (Document::SIGNED_URL_MINUTES * 60))
            ->and($query['X-Amz-Credential'])->toStartWith('PRIVATEKEYID/')->toContain('/sgp1/s3/');
    });

    test('files are written to their own bucket under the same relative key, private or public-read', function () {
        $requests = useOfflineSpaces();

        Storage::disk(Document::DISK)->putFileAs('documents', spacesUpload('%PDF-1.4', 'a.pdf'), 'a.pdf');
        Storage::disk(SiteSetting::DISK)->putFileAs('site', spacesUpload(spacesPng(), 'a.png'), 'a.png');

        expect($requests->getArrayCopy())->toContain(
            ['PutObject', SPACES_PRIVATE_BUCKET, 'documents/a.pdf', 'private'],
            ['PutObject', SPACES_PUBLIC_BUCKET, 'site/a.png', 'public-read'],
        );
    });
});

describe('the app on Spaces, with no code change', function () {
    beforeEach(function () {
        $this->admin = User::factory()->create();
    });

    test('a document upload goes to the private bucket under its key, and a download redirects to a short signed link', function () {
        $requests = useOfflineSpaces();
        $service = Service::factory()->create(['title' => 'Work Permit', 'has_documents' => true]);
        $record = ClientService::factory()->forService($service)->create(['fee' => '8000.00']);

        $this->actingAs($this->admin)->post(route('admin.clients.services.documents.store', [$record->client_id, $record]), [
            'title' => 'Work permit card', 'file' => spacesUpload("%PDF-1.4\nthe permit\n%%EOF\n", 'permit.pdf'),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $document = Document::query()->sole();

        expect($document->storage_key)->toMatch('#^documents/[0-9a-f-]{36}\.pdf$#')
            ->and($requests->getArrayCopy())->toContain(['PutObject', SPACES_PRIVATE_BUCKET, $document->storage_key, 'private']);

        $location = $this->actingAs($this->admin)
            ->get(route('admin.clients.services.documents.download', [$record->client_id, $record, $document]))
            ->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        expect(parse_url($location, PHP_URL_HOST))->toBe(SPACES_PRIVATE_BUCKET.'.sgp1.digitaloceanspaces.com')
            ->and(parse_url($location, PHP_URL_PATH))->toBe('/'.$document->storage_key)
            ->and($query['X-Amz-Expires'])->toBe('120')
            ->and($query['response-content-disposition'])->toStartWith('attachment;');
    });

    test('a client without access gets no signed link on Spaces either', function () {
        $requests = useOfflineSpaces();
        $service = Service::factory()->create(['title' => 'Work Permit', 'has_documents' => true]);
        $record = ClientService::factory()->forService($service)->create(['fee' => '8000.00']);
        $document = Document::factory()->create(['client_service_id' => $record->id, 'client_can_download' => false]);

        $this->get(route('client.documents.download', [$record->client->unique_token, $document->id]))->assertRedirect();

        expect($requests->getArrayCopy())->toBe([]);
    });

    test('a client photo goes to the private bucket and is still shown only through the signed, checked route', function () {
        $requests = useOfflineSpaces();
        $client = Client::factory()->create();

        $this->actingAs($this->admin)->post(route('admin.clients.photo.update', $client), [
            'photo' => spacesUpload(spacesJpeg(), 'me.jpg'),
        ])->assertSessionHasNoErrors();

        $client->refresh();

        expect($client->photo_path)->toMatch('#^client-photos/[0-9a-f-]{36}\.jpg$#')
            ->and($requests->getArrayCopy())->toContain(['PutObject', SPACES_PRIVATE_BUCKET, $client->photo_path, 'private'])
            ->and($client->photoUrl())->toStartWith(route('client-photos.show', $client));

        $response = $this->actingAs($this->admin)->get($client->photoUrl())->assertOk();

        expect($response->streamedContent())->toBe(spacesJpeg())
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');

        auth()->logout();
        $this->get($client->photoUrl())->assertForbidden();
    });

    test('website images go to the public bucket as public-read and are shown from the public assets URL', function () {
        $requests = useOfflineSpaces();

        $this->actingAs($this->admin)->post(route('admin.settings.appearance.update'), [
            'logo' => spacesUpload(spacesPng(), 'logo.png'), 'favicon' => spacesUpload(spacesPng(), 'favicon.png'),
        ])->assertSessionHasNoErrors();

        $setting = SiteSetting::query()->sole();

        expect($requests->getArrayCopy())->toContain(['PutObject', SPACES_PUBLIC_BUCKET, $setting->logo_key, 'public-read'])
            ->and(collect($requests)->where(1, SPACES_PRIVATE_BUCKET))->toBeEmpty();

        $this->actingAs($this->admin)->get(route('admin.settings.appearance.edit'))
            ->assertInertia(fn (Assert $page) => $page->where(
                'appearance.logo_url',
                fn (string $url) => str_starts_with($url, SPACES_PUBLIC_URL.'/'.$setting->logo_key.'?v='),
            ));
    });
});
