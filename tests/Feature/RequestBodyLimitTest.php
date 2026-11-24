<?php

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

    $this->call('POST', '/api/v1/_probe', server: ['CONTENT_TYPE' => 'application/json'], content: $body)
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
