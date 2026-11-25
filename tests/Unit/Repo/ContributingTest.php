<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// CONTRIBUTING.md and CLAUDE.md are read as data. The release guard (task 4)
// recognises a milestone release only by the standard merge subject, so the
// subject documented here has to be the one GitHub writes for a merge commit.

function repoDocument(string $name): string
{
    return (string) file_get_contents(dirname(__DIR__, 3).'/'.$name);
}

/**
 * The text of a level-2 section, up to the next level-2 heading.
 */
function markdownSection(string $markdown, string $heading): string
{
    $start = strpos($markdown, "\n## {$heading}\n");
    if ($start === false) {
        return '';
    }
    $rest = substr($markdown, $start + strlen($heading) + 5);
    $end = strpos($rest, "\n## ");

    return $end === false ? $rest : substr($rest, 0, $end);
}

/**
 * Prose only: fenced code blocks, inline code and HTML comments removed.
 */
function proseOf(string $markdown): string
{
    $markdown = (string) preg_replace('/^(```|~~~).*?^\1[^\n]*$/ms', '', $markdown);
    $markdown = (string) preg_replace('/<!--.*?-->/s', '', $markdown);

    return (string) preg_replace('/`[^`\n]*`/', '', $markdown);
}

it('documents the milestone merge subject exactly as the release guard expects', function () {
    $section = markdownSection(repoDocument('CONTRIBUTING.md'), 'Milestones, branches and releases');

    expect($section)->not->toBe('')
        ->and($section)->toMatch('/Merge pull request #<?[A-Za-z]*>? from strausmann\/milestone\/<?[0-9xyzXYZ.]+>?/')
        ->and($section)->toContain('merge commit')
        ->and($section)->toContain('milestone/X.Y.x');
});

it('keeps claude.md and contributing free of german prose markers', function (string $file) {
    $prose = proseOf(repoDocument($file));

    expect($prose)->not->toBe('')
        ->and(preg_match_all('/[äöüÄÖÜß„“]/u', $prose))->toBe(0);
})->with(['CLAUDE.md', 'CONTRIBUTING.md']);
