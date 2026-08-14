// Commit message rules. Two deliberate departures from
// @commitlint/config-conventional's defaults, both because the default made the
// gate go red for messages that are correct:
//
//  1. `subject-case`: the previous configuration demanded a fully lower-case
//     subject. This repository's subjects name Go identifiers and proper nouns
//     -- `MiddlewarePublic`, `CheckInventory`, `createUserFromInvite`, `OIDC`,
//     `PKCE` -- and lower-casing those would misspell them. The rule below is
//     config-conventional's own: it rejects a subject written *entirely* in
//     sentence, start, pascal or upper case, and leaves identifiers inside a
//     sentence alone.
//
//  2. `ignores`: git's own merge message ("Merge remote-tracking branch '...'")
//     carries no conventional type and never can. commitlint's built-in
//     defaults only skip "Merge branch"/"Merge pull request", not the
//     remote-tracking form this repository's integration merges produce. The
//     match is case-insensitive because one integration merge was written by
//     hand as "merge: integrate upstream ..." -- verified to be a real merge
//     commit (two parents), not a normal commit that happens to start with the
//     word.
//
// Everything else config-conventional enforces stays: allowed types, a scope
// format, no trailing period, and the header length limit below.
module.exports = {
  extends: ["@commitlint/config-conventional"],
  rules: {
    "subject-case": [2, "never", ["sentence-case", "start-case", "pascal-case", "upper-case"]],
    "header-max-length": [2, "always", 100],
  },
  ignores: [(message) => /^merge[:\s]/i.test(message)],
};
