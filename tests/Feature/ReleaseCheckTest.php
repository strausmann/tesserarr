<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\User;
use App\Updates\ReleaseCheck;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// Whether a newer release of Tesserarr has been published (GitHub releases,
// App\Updates\ReleaseCheck). The scheduler asks every six hours; pages only
// read what the last check stored, so no page ever waits for GitHub. The
// requests go to a fake (Http::fake) or to a socket of the test, never to
// the internet.

const RELEASE_URL = 'https://api.github.com/repos/strausmann/tesserarr/releases/latest';

beforeEach(function () {
    Http::preventStrayRequests();
    config(['app.version' => '0.1.0', 'tesserarr.release_check.enabled' => true]);
});

/** A partial reload of the dashboard that asks for the prop `release`. */
function releaseProp(TestCase $test, User $user): mixed
{
    $page = $test->actingAs($user)->get('/dashboard', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => '0.1.0',
        'X-Inertia-Partial-Component' => 'Dashboard',
        'X-Inertia-Partial-Data' => 'release',
    ])->assertOk()->json();

    expect($page['props'])->toHaveKey('release');

    return $page['props']['release'];
}

it('stores the newest release and tells the operator when it is newer', function () {
    Http::fake([RELEASE_URL => Http::response(['tag_name' => 'v0.2.0', 'name' => 'ignored', 'body' => '<b>x</b>'])]);

    $this->artisan('tesserarr:release-check')->assertSuccessful();

    expect(releaseProp($this, User::factory()->create()))->toMatchArray([
        'status' => 'known',
        'latest' => '0.2.0',
        'newer' => true,
        'url' => 'https://github.com/strausmann/tesserarr/releases/tag/v0.2.0',
    ]);
});

it('says the running version is the newest when GitHub names it', function () {
    Http::fake([RELEASE_URL => Http::response(['tag_name' => 'v0.1.0'])]);

    $this->artisan('tesserarr:release-check')->assertSuccessful();

    expect(releaseProp($this, User::factory()->create()))->toMatchArray(['latest' => '0.1.0', 'newer' => false]);
});

it('loads the release only when the page asks for it', function () {
    Http::fake([RELEASE_URL => Http::response(['tag_name' => 'v0.2.0'])]);
    $this->artisan('tesserarr:release-check')->assertSuccessful();

    $this->actingAs(User::factory()->create())->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->missing('release'));
});

it('sends no cookie, no credential and nothing about the installation', function () {
    Http::fake([RELEASE_URL => Http::response(['tag_name' => 'v0.2.0'])]);
    config(['app.url' => 'https://media.example.org', 'app.name' => 'Media of Alice']);

    $this->artisan('tesserarr:release-check')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $headers = array_change_key_case($request->headers());
        $values = strtolower(json_encode($headers, JSON_THROW_ON_ERROR));

        return $request->url() === RELEASE_URL
            && $request->method() === 'GET'
            && $request->body() === ''
            && $headers['user-agent'] === ['Tesserarr']
            && ! isset($headers['authorization'])
            && ! isset($headers['cookie'])
            && ! str_contains($values, 'example.org')
            && ! str_contains($values, 'alice')
            && ! str_contains($values, '0.1.0');
    });
});

it('keeps the last known release when GitHub cannot be reached', function () {
    Http::fakeSequence(RELEASE_URL)
        ->push(['tag_name' => 'v0.2.0'])
        ->pushFailedConnection();
    $this->artisan('tesserarr:release-check')->assertSuccessful();
    $stored = Cache::get(ReleaseCheck::CACHE_KEY);

    // A failed check is no error for the scheduler and changes nothing.
    $this->artisan('tesserarr:release-check')->assertSuccessful();

    expect(Cache::get(ReleaseCheck::CACHE_KEY))->toBe($stored)
        ->and(releaseProp($this, User::factory()->create()))->toMatchArray(['latest' => '0.2.0']);
});

it('keeps the last known release when GitHub answers with an error', function () {
    Http::fakeSequence(RELEASE_URL)
        ->push(['tag_name' => 'v0.2.0'])
        ->push(['message' => 'API rate limit exceeded'], 403)
        ->push('<html>not json</html>', 200);
    $this->artisan('tesserarr:release-check')->assertSuccessful();

    $this->artisan('tesserarr:release-check')->assertSuccessful();
    $this->artisan('tesserarr:release-check')->assertSuccessful();

    expect(releaseProp($this, User::factory()->create()))->toMatchArray(['latest' => '0.2.0']);
});

it('stores nothing for a tag that is not a plain version', function (mixed $tag) {
    Http::fake([RELEASE_URL => Http::response(['tag_name' => $tag])]);

    $this->artisan('tesserarr:release-check')->assertSuccessful();

    expect(Cache::get(ReleaseCheck::CACHE_KEY))->toBeNull()
        ->and(releaseProp($this, User::factory()->create()))->toBe(['status' => 'unknown']);
})->with([
    'markup' => '<img src=x onerror=alert(1)>',
    'a prerelease' => 'v0.2.0-rc.1',
    'trailing text' => 'v0.2.0"><script>',
    'a trailing newline' => "v0.2.0\n",
    'no version' => 'latest',
    'not a string' => [['v0.2.0']],
]);

it('ignores a draft or a prerelease GitHub hands out anyway', function () {
    Http::fake([RELEASE_URL => Http::response(['tag_name' => 'v0.2.0', 'prerelease' => true])]);

    $this->artisan('tesserarr:release-check')->assertSuccessful();

    expect(Cache::get(ReleaseCheck::CACHE_KEY))->toBeNull();
});

it('asks no one when the check is switched off', function () {
    Http::fake([RELEASE_URL => Http::response(['tag_name' => 'v0.2.0'])]);
    config(['tesserarr.release_check.enabled' => false]);
    Cache::forever(ReleaseCheck::CACHE_KEY, ['version' => '0.2.0', 'checked_at' => now()->toIso8601String()]);

    $this->artisan('tesserarr:release-check')->assertSuccessful();

    Http::assertNothingSent();
    expect(releaseProp($this, User::factory()->create()))->toBe(['status' => 'off']);
});

it('is off by default: no request leaves the instance and About says so', function () {
    Http::fake([RELEASE_URL => Http::response(['tag_name' => 'v0.2.0'])]);
    // The shipped configuration, without TESSERARR_RELEASE_CHECK set.
    config(['tesserarr.release_check' => (require base_path('config/tesserarr.php'))['release_check']]);

    expect(config('tesserarr.release_check.enabled'))->toBeFalse();
    $this->artisan('tesserarr:release-check')
        ->expectsOutputToContain('TESSERARR_RELEASE_CHECK=true')
        ->assertSuccessful();
    app(ReleaseCheck::class)->refresh();

    Http::assertNothingSent();
    expect(releaseProp($this, User::factory()->create()))->toBe(['status' => 'off'])
        ->and((string) file_get_contents(base_path('.env.example')))->toContain("TESSERARR_RELEASE_CHECK=false\n");
});

it('asks no one when switched off, also when called directly', function () {
    // The command checks the switch first; the class must not rely on it.
    Http::fake([RELEASE_URL => Http::response(['tag_name' => 'v0.2.0'])]);
    config(['tesserarr.release_check.enabled' => false]);

    expect(app(ReleaseCheck::class)->refresh())->toBeNull();
    Http::assertNothingSent();
});

it('gives up on a server that does not answer', function () {
    // A socket of this test that accepts the connection and never answers.
    $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    expect($server)->not->toBeFalse();
    $address = (string) stream_socket_get_name($server, false);
    Http::allowStrayRequests();
    config(['tesserarr.release_check.url' => "http://{$address}/latest", 'tesserarr.release_check.timeout' => 1]);

    $started = microtime(true);
    $release = app(ReleaseCheck::class)->refresh();
    $took = microtime(true) - $started;
    fclose($server);

    // Relative to the configured timeout, not an absolute limit: it waited
    // for the timeout (so the socket really held it) and gave up within a
    // few times of it, far below Guzzle's own 30 s.
    expect($release)->toBeNull()
        ->and($took)->toBeGreaterThanOrEqual(0.9)
        ->and($took)->toBeLessThan(1 * 5);
});

it('checks every six hours, from the scheduler only', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'tesserarr:release-check'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 */6 * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue()
        ->and($events->first()->expiresAt)->toBe(10);
});

it('refuses an answer larger than 64 KiB', function () {
    $padding = str_repeat('x', ReleaseCheck::MAX_BODY_BYTES);
    Http::fake([RELEASE_URL => Http::response(['tag_name' => 'v0.2.0', 'body' => $padding])]);

    $this->artisan('tesserarr:release-check')->assertSuccessful();

    expect(Cache::get(ReleaseCheck::CACHE_KEY))->toBeNull()
        ->and(ReleaseCheck::MAX_BODY_BYTES)->toBe(64 * 1024);
});

it('reads an answer just below the limit', function () {
    $json = json_encode(['tag_name' => 'v0.2.0', 'body' => ''], JSON_THROW_ON_ERROR);
    $padding = str_repeat('x', ReleaseCheck::MAX_BODY_BYTES - strlen($json));
    Http::fake([RELEASE_URL => Http::response(['tag_name' => 'v0.2.0', 'body' => $padding])]);

    $this->artisan('tesserarr:release-check')->assertSuccessful();

    expect(Cache::get(ReleaseCheck::CACHE_KEY)['version'] ?? null)->toBe('0.2.0');
});

it('does not stop the command when the connection fails', function () {
    Http::fake([RELEASE_URL => fn () => throw new ConnectionException('down')]);

    $this->artisan('tesserarr:release-check')->assertSuccessful();

    expect(Cache::get(ReleaseCheck::CACHE_KEY))->toBeNull();
});
