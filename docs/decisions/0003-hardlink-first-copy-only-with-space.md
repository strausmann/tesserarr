<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# ADR-0003: Hardlink first, a copy only when there is space

- **Status:** accepted
- **Date:** 2026-08-13, revised 2026-11-24
- **Supersedes:** none
- **Superseded by:** none
- **Revises:** none
- **Revised by:** none
- **Related:** [ADR-0001](0001-provider-side-service-receiver-instance-optional.md), [ADR-0013](0013-satellite-pull-model.md)

## Context

When a request is accepted, the file has to get from the media library into an outgoing folder that Syncthing sends.
Three ways were possible:

- **Symbolic link:** Syncthing does not follow symbolic links but sends the link itself; the receiver would get a
  dead link.
- **Always copy:** works everywhere, but every open request costs the full size again, for a season in high resolution
  easily hundreds of gigabytes until it is collected or expires.
- **Hardlink, copy as fallback:** a hardlink is a second directory entry for the same data, without extra space or copy
  time, but only **inside one file system**. Media shares are often separate file systems or subvolumes, so a hardlink
  never crosses from one share into another.

Whether Syncthing sends a hardlinked file like an ordinary file with its full content is expected (it identifies files
by path and content hash, not by inode) but not yet proven; trial V1 tests it before 0.2.0.

## Decision

1. **Each media share has its own outgoing area** inside it (for example `<share>/.tesserarr/<receiver>/`), and
   **each share and receiver its own Syncthing folder** (send-only at the provider, receive-only at the receiver). In
   managed mode Tesserarr creates these folders and removes them on revocation.
2. Provisioning tries a **hardlink first**, from the file into the outgoing area of its own share.
3. When the hardlink fails, it **copies, but only when there is enough free space**. The free space is checked before
   every copy; when it is not enough, the provisioning is refused with a clear message instead of filling the volume.
4. The way a file was provided (hardlink or copy) is stored with the request and shown in the interface, and the space it
   took is written to the audit log.
5. Clean-up is symmetric: a hardlink entry is removed without touching the original, a copy is deleted.
6. The file work runs where the share is mounted: in a satellite next to the storage, or in the central instance when
   it sees the same file system ([ADR-0013](0013-satellite-pull-model.md)).

## Consequences

- In the normal case there is no extra space and no copy time; provisioning is done at once.
- An outgoing area per share keeps hardlinks possible even when every share is its own file system.
- A copy can no longer fill a volume; on a nearly full volume the fallback is simply not available, and the operator
  sees why.
- More Syncthing folders: one per share and receiver instead of one per receiver.
- A hardlink shares the data with the original. A tool that rewrites the original during a transfer changes what is sent.
- File systems differ in their hardlink behaviour (copy-on-write file systems among them); not every one is tested.

**Revisit** when trial V1 shows that Syncthing does not send hardlinked files as full files.

## History

Recorded for the Go version (tag `archive/go-0.0.2`) with one outgoing folder `outgoing/<request>/` and an unbounded
copy fallback. Revised with the move to Laravel: an outgoing area per media share, a Syncthing folder per share and
receiver, and a copy only when there is space.
