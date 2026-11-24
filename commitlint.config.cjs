// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/* commitlint enforces Conventional Commits on the pull request title (commitlint.yml). */

module.exports = {
  extends: ['@commitlint/config-conventional'],
  rules: {
    'type-enum': [
      2,
      'always',
      ['feat', 'fix', 'perf', 'docs', 'style', 'refactor', 'test', 'chore', 'ci', 'build', 'revert'],
    ],
    'subject-case': [2, 'never', ['upper-case', 'pascal-case', 'start-case']],
    'subject-empty': [2, 'never'],
    'subject-full-stop': [2, 'never', '.'],
    // 120 instead of 100: grouped Dependabot titles run 100-110 characters.
    'header-max-length': [2, 'always', 120],
  },
};
