<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;

it('rejects a json body above 64 KiB with 413 before validation runs', function () {
    Route::middleware('token-api')->post('/api/v1/_probe', fn () => abort(500, 'must not run')); // route owned by this test
    $this->postJson('/api/v1/_probe', ['pad' => str_repeat('a', 70_000)])
        ->assertStatus(413)
        ->assertExactJson(['error' => 'payload_too_large']);
});

it('accepts a json body of exactly 64 KiB', function () {
    Route::middleware('token-api')->post('/api/v1/_probe', fn () => response()->json(['ok' => true]));
    $body = '{"pad":"'.str_repeat('a', 65_536 - 10).'"}';
    expect(strlen($body))->toBe(65_536);

    $this->call('POST', '/api/v1/_probe', server: ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => '65536'], content: $body)
        ->assertOk();
});

it('measures the bytes actually read, not the Content-Length header', function () {
    Route::middleware('token-api')->post('/api/v1/_probe', fn () => abort(500, 'must not run'));

    $this->call(
        'POST',
        '/api/v1/_probe',
        server: ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => '10'],
        content: '{"pad":"'.str_repeat('a', 70_000).'"}',
    )->assertStatus(413)->assertExactJson(['error' => 'payload_too_large']);
});

it('rejects a declared Content-Length above 64 KiB without reading the body', function () {
    Route::middleware('token-api')->post('/api/v1/_probe', fn () => abort(500, 'must not run'));

    $this->call('POST', '/api/v1/_probe', server: ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => '65537'], content: '{}')
        ->assertStatus(413);
});

it('applies the 64 KiB limit to /auth paths', function () {
    Route::post('/auth/_probe', fn () => abort(500, 'must not run'));

    $this->postJson('/auth/_probe', ['pad' => str_repeat('a', 70_000)])->assertStatus(413);
});

it('leaves other paths to the proxy limit', function () {
    Route::post('/_probe/upload', fn () => response('ok'));

    $this->postJson('/_probe/upload', ['pad' => str_repeat('a', 70_000)])->assertOk();
});

it('lets a route declare its own limit with limit.body', function () {
    Route::middleware(['token-api', 'limit.body:1048576'])->post('/api/v1/_probe/batch', fn () => response()->json(['ok' => true]));

    $this->postJson('/api/v1/_probe/batch', ['pad' => str_repeat('a', 70_000)])->assertOk();
    $this->postJson('/api/v1/_probe/batch', ['pad' => str_repeat('a', 1_048_600)])->assertStatus(413);
});

// PHP parses multipart/form-data before the application runs; php://input is
// then empty, so reading the stream measures nothing. These requests carry
// their payload only in the parsed files, as FrankenPHP delivers them.
function multipartProbe(array $server = []): array
{
    return [
        'files' => ['blob' => UploadedFile::fake()->create('blob.bin', 70)], // 70 KiB
        'server' => ['CONTENT_TYPE' => 'multipart/form-data; boundary=probe', ...$server],
    ];
}

it('answers 415, not 411, to a chunked multipart body on a route without multipart', function () {
    Route::middleware('token-api')->post('/api/v1/_probe', fn () => abort(500, 'must not run'));
    $probe = multipartProbe(['HTTP_TRANSFER_ENCODING' => 'chunked']);

    $this->call('POST', '/api/v1/_probe', files: $probe['files'], server: $probe['server'])
        ->assertStatus(415)
        ->assertExactJson(['error' => 'unsupported_media_type']);
});

it('answers 411 to a chunked body without Content-Length on a route that allows multipart', function () {
    Route::middleware(['token-api', 'limit.body:65536,multipart'])->post('/api/v1/_probe/upload', fn () => abort(500, 'must not run'));
    $probe = multipartProbe(['HTTP_TRANSFER_ENCODING' => 'chunked']);

    $this->call('POST', '/api/v1/_probe/upload', files: $probe['files'], server: $probe['server'])
        ->assertStatus(411)
        ->assertExactJson(['error' => 'length_required']);
});

it('lets a POST without body and without Content-Length through', function () {
    Route::middleware('token-api')->post('/api/v1/_probe/action', fn () => response()->json(['ok' => true]));

    // FrankenPHP passes a missing length as an empty CONTENT_LENGTH.
    $this->call('POST', '/api/v1/_probe/action', server: ['CONTENT_LENGTH' => ''])->assertOk();
});

it('lets a DELETE without Content-Length through and still bounds its body', function () {
    Route::middleware('token-api')->delete('/api/v1/_probe/item', fn () => response()->json(['ok' => true]));

    $this->call('DELETE', '/api/v1/_probe/item', server: ['CONTENT_LENGTH' => ''])->assertOk();
    $this->call('DELETE', '/api/v1/_probe/item', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_TRANSFER_ENCODING' => 'chunked'], content: '{"pad":"'.str_repeat('a', 70_000).'"}')
        ->assertStatus(413);
});

it('rejects multipart/form-data with 415 on a limited path', function () {
    Route::middleware('token-api')->post('/api/v1/_probe', fn () => abort(500, 'must not run'));
    $probe = multipartProbe(['CONTENT_LENGTH' => '100']); // declared length smaller than the file

    $this->call('POST', '/api/v1/_probe', files: $probe['files'], server: $probe['server'])
        ->assertStatus(415)
        ->assertExactJson(['error' => 'unsupported_media_type']);
});

it('counts the parsed files on a route that allows multipart', function () {
    Route::middleware(['token-api', 'limit.body:65536,multipart'])->post('/api/v1/_probe/upload', fn () => response()->json(['ok' => true]));

    $small = ['blob' => UploadedFile::fake()->create('small.bin', 1)];
    $this->call('POST', '/api/v1/_probe/upload', files: $small, server: ['CONTENT_TYPE' => 'multipart/form-data; boundary=probe', 'CONTENT_LENGTH' => '1500'])
        ->assertOk();

    $probe = multipartProbe(['CONTENT_LENGTH' => '100']);
    $this->call('POST', '/api/v1/_probe/upload', files: $probe['files'], server: $probe['server'])
        ->assertStatus(413);
});

// I1: JSON is measured by its raw bytes, never re-encoded as a form.
it('lets a 40 KB JSON body of umlauts through', function () {
    Route::middleware('token-api')->post('/api/v1/_probe', fn () => response()->json(['ok' => true]));
    $body = json_encode(['text' => str_repeat('ä', 20_000)], JSON_UNESCAPED_UNICODE);
    expect(strlen($body))->toBeLessThan(65_536)->toBeGreaterThan(40_000);

    $this->call('POST', '/api/v1/_probe', server: ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => (string) strlen($body)], content: $body)
        ->assertOk();
});

it('lets a 24 KB JSON list of numbers through', function () {
    Route::middleware('token-api')->post('/api/v1/_probe', fn () => response()->json(['ok' => true]));
    $body = json_encode(['ids' => range(1000, 5999)]);
    expect(strlen($body))->toBeLessThan(30_000)->toBeGreaterThan(24_000);

    $this->call('POST', '/api/v1/_probe', server: ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => (string) strlen($body)], content: $body)
        ->assertOk();
});

it('rejects a 65 KiB JSON body with 413', function () {
    Route::middleware('token-api')->post('/api/v1/_probe', fn () => abort(500, 'must not run'));
    $body = json_encode(['text' => str_repeat('ä', 33_300)], JSON_UNESCAPED_UNICODE);
    expect(strlen($body))->toBeGreaterThan(65_536);

    $this->call('POST', '/api/v1/_probe', server: ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => (string) strlen($body)], content: $body)
        ->assertStatus(413);
});

// I3: the login endpoints are bounded by route name, not by a path prefix.
it('rejects a 70 KB body on POST /login with 413', function () {
    $this->postJson(route('login.store'), ['login' => 'alice', 'password' => str_repeat('a', 70_000)])
        ->assertStatus(413)
        ->assertExactJson(['error' => 'payload_too_large']);
});

it('rejects a 70 KB body on the passkey login with 413', function () {
    $this->postJson(route('passkey.login'), ['credential' => ['id' => str_repeat('a', 70_000)]])
        ->assertStatus(413);
});

it('bounds every authentication endpoint by route name', function (string $name) {
    $route = app('router')->getRoutes()->getByName($name);
    expect($route)->not->toBeNull();

    $method = in_array('POST', $route->methods(), true) ? 'POST' : 'GET';
    $this->call($method, '/'.$route->uri(), server: ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => '70000'], content: str_repeat('a', 70_000))
        ->assertStatus(413);
})->with([
    'login.store', 'two-factor.login.store', 'password.confirm.store',
    'passkey.login', 'passkey.login-options', 'passkey.confirm', 'passkey.confirm-options', 'passkey.registration-options',
]);
