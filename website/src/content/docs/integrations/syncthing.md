---
title: Syncthing
description: Connect your Syncthing instance, the transport of every file.
---

Tesserarr talks to Syncthing over its REST API with the API key of the instance. In 0.1.0 it tests the connection and
shows the status. From 0.2.0 it manages one folder per media share and friend (send-only on your side, receive-only on
theirs), measures progress per device and folder and notices when a title was collected.

Syncthing reaches your friends directly or through its relays; you open no port of your own Syncthing to them.

**Status:** this connection arrives with 0.1.0.
