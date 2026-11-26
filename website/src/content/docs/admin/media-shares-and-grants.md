---
title: Media shares and grants
description: Media shares, grants at any folder depth, and how a path is checked.
---

A **media share** is a root folder at one place of execution: a satellite, or the central instance itself when it sees
the files. You grant folders of a share at any depth, to everyone (a standard grant) or to one user.

- Every catalogue access goes through **one** grant check: browsing, search and, later, requests.
- The check works on the **resolved, real path**: no `..`, no symbolic link out of the share. When in doubt, the answer
  is no.
- Hardlinks only work inside one file system, so each media share gets its own outgoing area (decision record 0003).

**Status:** media shares, grants and the folder index arrive with 0.1.0.
