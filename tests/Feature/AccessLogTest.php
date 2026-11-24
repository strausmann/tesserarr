<?php

use Illuminate\Support\Facades\Route;
use Tests\Support\EnvSnapshot;

beforeEach(function () {
    $this->env = (new EnvSnapshot)->set(['TESSERARR_TRUSTED_PROXIES' => '10.0.0.1']);
    $this->refreshApplication();

    $this->logFile = tempnam(sys_get_temp_dir(), 'access-log-');
    config(['logging.channels.access.handler_with.stream' => $this->logFile]);

    Route::get('/_probe/access-log', fn () => response('hello', 201));
});

afterEach(function () {
    $this->env->restore();
    @unlink($this->logFile);
});

function accessLogLines(string $file): array
{
    return array_values(array_filter(explode("\n", (string) file_get_contents($file))));
}

it('logs the real client address in combined format', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeaders([
            'X-Forwarded-For' => '198.51.100.7',
            'Referer' => 'https://example.invalid/start',
            'User-Agent' => 'probe/1.0',
        ])
        ->get('/_probe/access-log');

    $lines = accessLogLines($this->logFile);

    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toMatch(
            '~^198\.51\.100\.7 - - \[\d{2}/[A-Z][a-z]{2}/\d{4}:\d{2}:\d{2}:\d{2} [+-]\d{4}\] "GET /_probe/access-log HTTP/1\.1" 201 5 "https://example\.invalid/start" "probe/1\.0"$~',
        );
});

it('logs the peer address when the request does not come from a trusted proxy', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->withHeader('X-Forwarded-For', '198.51.100.7')
        ->get('/_probe/access-log');

    expect(accessLogLines($this->logFile)[0])->toStartWith('10.0.0.2 - - [');
});

it('redacts query string values', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->get('/_probe/access-log?token=s3cr3t&page=2');

    expect(accessLogLines($this->logFile)[0])
        ->toContain('"GET /_probe/access-log?token=[redacted]&page=[redacted] HTTP/1.1"')
        ->not->toContain('s3cr3t');
});

it('escapes quotes and control characters so a header cannot forge a log line', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->withHeader('User-Agent', "evil\" 200 0 \"-\" \"x\nforged line")
        ->get('/_probe/access-log');

    $lines = accessLogLines($this->logFile);

    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toEndWith('"evil\\" 200 0 \\"-\\" \\"x\\x0Aforged line"');
});

it('logs only scheme, host and path of the referer', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->withHeader('Referer', 'https://example.invalid:8443/invite/accept?token=s3cr3t#frag')
        ->get('/_probe/access-log');

    expect(accessLogLines($this->logFile)[0])
        ->toContain(' "https://example.invalid:8443/invite/accept" ')
        ->not->toContain('s3cr3t')
        ->not->toContain('frag');
});
