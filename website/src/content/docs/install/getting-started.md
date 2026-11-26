---
title: Getting started
description: Start the central instance, create the first invitation and sign in.
---

**Status:** the compose file for a real installation and the setup checklist arrive with 0.1.0. This page describes the
steps that are settled.

## What you need

- Docker Engine with the compose plugin.
- PostgreSQL 18, in a container next to Tesserarr or elsewhere.
- An address your friends can reach, usually through a [reverse proxy](/tesserarr/install/reverse-proxy/) or a
  [tunnel](/tesserarr/install/tunnels/).

## First start

1. Generate the application key once and keep it: `docker run --rm docker.io/bstrausmann/tesserarr:<version> artisan key:generate --show`.
   Put it into the environment as `APP_KEY`.
2. Set the database variables and `APP_URL`, see [configuration](/tesserarr/install/configuration/).
3. Start the stack. The `migrate` container runs first; `web`, `worker` and `scheduler` wait until it has finished.
4. Check the health route: `/up` answers `200` when the instance and its database are up.

## The first account

There is no sign-up page. The first invitation comes from the command line, every later one from the web interface or
the API. The command for it is listed in the [command line reference](/tesserarr/reference/cli/) once it exists.
