---
name: Project Stack
description: Core technology versions and runtime environment for this project
type: project
---

This project uses CodeIgniter 4.7.2 with PHP 8.1+. The database is MariaDB 10.5.8 running in Docker on port 3306. The API is the persistence layer for the Observatory FITS Analysis Pipeline system.

**Why:** CI4 was bootstrapped via `composer create-project codeigniter4/appstarter` (installed into temp dir then rsynced to avoid overwriting existing project files).

**How to apply:** Always use CI4 namespaced conventions (`App\Controllers`, etc.), PHP 8.1+ features, and MySQLi driver for DB connections.
