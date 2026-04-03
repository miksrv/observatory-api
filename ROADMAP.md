# ROADMAP — Observatory API (PHP Backend)

Sequential implementation plan for the `observatory-api` CodeIgniter 4 REST server.
Each task is self-contained. Complete tasks in order — later tasks depend on earlier ones.

---

## Task 1 — Bootstrap CodeIgniter 4 Project ✅ DONE

**Goal:** Set up a working CI4 skeleton inside this repository.

**Steps:**
1. Install CodeIgniter 4 via Composer into the project root:
   ```bash
   composer create-project codeigniter4/appstarter . --no-interaction
   ```
   (Run from `/Users/mik/Projects/observatory-api/`)
2. Copy `env` → `.env`, set `CI_ENVIRONMENT = development`
3. Configure `.env` for the Docker MariaDB:
   ```
   database.default.hostname = 127.0.0.1
   database.default.database = db
   database.default.username = user
   database.default.password = password
   database.default.DBDriver = MySQLi
   database.default.port     = 3306
   ```
4. Add `.env` to `.gitignore` (keep `env` template committed)
5. Verify CI4 boots: `php spark serve` → opens at `http://localhost:8080`

**Acceptance criteria:**
- `php spark serve` starts without errors
- Default CI4 welcome page loads in the browser
- `php spark db:connect` reports a successful connection to MariaDB

---

## Task 2 — Start MariaDB with Docker ✅ DONE

**Goal:** Bring the database container up so all subsequent tasks can run migrations.

**Steps:**
1. From the project root, start the container:
   ```bash
   docker compose up -d
   ```
2. Confirm MariaDB is listening:
   ```bash
   docker compose ps
   # and/or
   mysql -h 127.0.0.1 -P 3306 -u user -ppassword db -e "SELECT 1;"
   ```
3. The container uses a named Docker volume `observatory-api` — data persists across restarts.

**Acceptance criteria:**
- `docker compose ps` shows `observatory-api` container as `Up`
- Can connect to MariaDB on `localhost:3306` with the credentials in `docker-compose.yml`

---

## Task 3 — Database Migrations ✅ DONE

**Goal:** Create the three core tables via CI4 migrations.

Create three migration files in `app/Database/Migrations/`:

### Migration 1 — `frames` table

Columns (all described in `CLAUDE.md` under Database Schema):
- `id` INT UNSIGNED AUTO_INCREMENT PK
- `filename` VARCHAR(255) NOT NULL
- `original_filepath` VARCHAR(500)
- `obs_time` DATETIME NOT NULL
- `ra_center` DOUBLE NOT NULL
- `dec_center` DOUBLE NOT NULL
- `fov_deg` FLOAT NOT NULL
- `quality_flag` VARCHAR(20) DEFAULT 'OK'
- `object` VARCHAR(100)
- `exptime` FLOAT
- `filter` VARCHAR(50)
- `frame_type` VARCHAR(20)
- `airmass` FLOAT
- `telescope` VARCHAR(255)
- `camera` VARCHAR(255)
- `focal_length_mm` INT
- `aperture_mm` INT
- `sensor_temp` FLOAT
- `sensor_temp_setpoint` FLOAT
- `binning_x` TINYINT
- `binning_y` TINYINT
- `gain` INT
- `offset` INT
- `width_px` INT
- `height_px` INT
- `observer_name` VARCHAR(255)
- `site_name` VARCHAR(255)
- `site_lat` DOUBLE
- `site_lon` DOUBLE
- `site_elev_m` INT
- `software_capture` VARCHAR(255)
- `qc_fwhm_median` FLOAT
- `qc_elongation` FLOAT
- `qc_snr_median` FLOAT
- `qc_sky_background` FLOAT
- `qc_star_count` INT
- `qc_eccentricity` FLOAT
- `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP

Indexes: `(ra_center, dec_center)`, `obs_time`, `filename`

### Migration 2 — `sources` table

Columns:
- `id` BIGINT UNSIGNED AUTO_INCREMENT PK
- `frame_id` INT UNSIGNED NOT NULL (FK → frames.id ON DELETE CASCADE)
- `ra` DOUBLE NOT NULL
- `dec` DOUBLE NOT NULL
- `mag` FLOAT
- `flux` FLOAT
- `fwhm` FLOAT
- `catalog_name` VARCHAR(50)
- `catalog_id` VARCHAR(255)
- `catalog_mag` FLOAT
- `object_type` VARCHAR(50)
- `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP

Indexes: `(ra, dec)`, `frame_id`, `catalog_name`

### Migration 3 — `anomalies` table

Columns:
- `id` INT UNSIGNED AUTO_INCREMENT PK
- `frame_id` INT UNSIGNED NOT NULL (FK → frames.id ON DELETE CASCADE)
- `anomaly_type` VARCHAR(30) NOT NULL
- `ra` DOUBLE NOT NULL
- `dec` DOUBLE NOT NULL
- `magnitude` FLOAT
- `delta_mag` FLOAT
- `mpc_designation` VARCHAR(100)
- `ephemeris_predicted_ra` DOUBLE
- `ephemeris_predicted_dec` DOUBLE
- `ephemeris_predicted_mag` FLOAT
- `ephemeris_distance_au` FLOAT
- `ephemeris_angular_velocity` FLOAT
- `notes` TEXT
- `is_alert` TINYINT(1) DEFAULT 0
- `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP

Indexes: `frame_id`, `anomaly_type`, `is_alert`, `(ra, dec)`

**Run migrations:**
```bash
php spark migrate
```

**Acceptance criteria:**
- `php spark migrate` completes without errors
- All three tables exist in the `db` database with correct columns and indexes

---

## Task 4 — API Key Authentication Filter ✅ DONE

**Goal:** Every request to `/api/v1/*` must be authenticated via `X-API-Key` header.

**Steps:**

1. Add `API_KEY` to `.env`:
   ```
   app.apiKey = your-secret-key-here
   ```
2. Create `app/Filters/ApiKeyFilter.php`:
   - Read `X-API-Key` from request headers
   - Compare against `env('app.apiKey')` or `config('App')->apiKey`
   - If missing or wrong → return `401` JSON response:
     ```json
     { "error": "Unauthorized", "details": {} }
     ```
   - If valid → continue to controller
3. Register the filter in `app/Config/Filters.php` as an alias (e.g. `'api_key'`)
4. Apply the filter to the route group in `app/Config/Routes.php`

**Acceptance criteria:**
- `GET /api/v1/frames/covering?ra=0&dec=0&before_time=2024-01-01T00:00:00Z` with no header → `401`
- Same request with correct `X-API-Key` header → `200` (even if empty result)
- Wrong key → `401`

---

## Task 5 — POST /api/v1/frames ✅ DONE

**Goal:** Implement the "Register a Frame" endpoint.

**Steps:**

1. Create `app/Controllers/Api/V1/FramesController.php`
2. Create `app/Models/FrameModel.php` (CI4 model, table `frames`)
3. In `FramesController::create()`:
   - Parse JSON body
   - Validate required fields: `filename`, `obs_time`, `ra_center`, `dec_center`, `fov_deg`, `quality_flag`
   - Flatten nested objects (`observation.*`, `instrument.*`, `sensor.*`, `observer.*`, `software.*`, `qc.*`) into the flat DB schema
   - Insert into `frames` table
   - Return `201`:
     ```json
     { "id": "42", "message": "Frame registered successfully" }
     ```
4. Register route: `$routes->post('api/v1/frames', 'Api\V1\FramesController::create');`
5. Error handling:
   - Missing required fields → `400`
   - Validation failure (e.g. non-numeric coordinates) → `422` with field details

**Acceptance criteria:**
- Valid POST creates a record in `frames` and returns `{ "id": "...", "message": "..." }`
- Missing `filename` → `400`
- Non-numeric `ra_center` → `422`
- No API key → `401`
- The returned `id` is usable in subsequent source/anomaly calls

---

## Task 6 — POST /api/v1/frames/{id}/sources ✅ DONE

**Goal:** Save all sources detected in a frame.

**Steps:**

1. Create `app/Models/SourceModel.php` (table `sources`)
2. Add `FramesController::saveSources($id)` (or a separate `SourcesController`)
3. Logic:
   - Verify frame `{id}` exists in `frames` → `404` if not
   - Validate body: `filename` (string) and `sources` (array) are required
   - Batch-insert all sources with `frame_id = $id`
   - Return `201`:
     ```json
     { "message": "Sources saved successfully", "count": 287 }
     ```
4. Register route:
   ```
   $routes->post('api/v1/frames/(:num)/sources', 'Api\V1\FramesController::saveSources/$1');
   ```
5. An empty `sources` array `[]` is valid — insert nothing, return count 0.

**Acceptance criteria:**
- Saves N sources and returns correct `count`
- `sources[].ra` and `sources[].dec` are required per source; all other fields nullable
- Frame not found → `404`
- Missing `sources` field → `400`

---

## Task 7 — POST /api/v1/frames/{id}/anomalies ✅ DONE

**Goal:** Save classified anomalies for a frame and count alert-worthy ones.

**Steps:**

1. Create `app/Models/AnomalyModel.php` (table `anomalies`)
2. Add `FramesController::saveAnomalies($id)`
3. Logic:
   - Verify frame `{id}` exists → `404` if not
   - Validate: `filename` and `anomalies` array required
   - For each anomaly, set `is_alert = 1` if `anomaly_type` is one of:
     `SUPERNOVA_CANDIDATE`, `MOVING_UNKNOWN`, `SPACE_DEBRIS`, `UNKNOWN`
   - Flatten `ephemeris` nested object into flat columns
   - Batch-insert into `anomalies`
   - Return `201`:
     ```json
     { "message": "Anomalies saved successfully", "count": 4, "alerts": 2 }
     ```
4. Register route:
   ```
   $routes->post('api/v1/frames/(:num)/anomalies', 'Api\V1\FramesController::saveAnomalies/$1');
   ```

**Acceptance criteria:**
- Saves anomalies with correct `is_alert` flag
- `alerts` in response equals count of alert-worthy anomaly_type values
- Empty array → count 0, alerts 0
- Unknown `anomaly_type` values are still saved (no allowlist enforcement)

---

## Task 8 — GET /api/v1/sources/near ✅ DONE

**Goal:** Cone search for historical sources near a sky position.

**Steps:**

1. Create `app/Controllers/Api/V1/SourcesController.php`
2. Add `SourcesController::near()`:
   - Validate required query params: `ra`, `dec`, `radius_arcsec`, `before_time`
   - `before_time` is ISO 8601 → parse to DateTime, convert to MySQL DATETIME string
   - Bounding-box pre-filter:
     ```sql
     WHERE s.ra  BETWEEN :ra  - :deg AND :ra  + :deg
       AND s.dec BETWEEN :dec - :deg AND :dec + :deg
       AND f.obs_time < :before_time
     ```
     Where `deg = radius_arcsec / 3600.0`
   - JOIN `sources` with `frames` on `frame_id` to get `obs_time`
   - Apply Haversine filter in PHP to get precise angular distances
   - Return `200`:
     ```json
     {
       "data": [
         { "ra": ..., "dec": ..., "mag": ..., "flux": ..., "frame_id": "38", "obs_time": "..." }
       ]
     }
     ```
   - Return `{"data": []}` when no results
3. Register route:
   ```
   $routes->get('api/v1/sources/near', 'Api\V1\SourcesController::near');
   ```

**Haversine helper** (add as a private method or a helper file):
```php
private function haversineArcsec(float $ra1, float $dec1, float $ra2, float $dec2): float
{
    $ra1 = deg2rad($ra1); $dec1 = deg2rad($dec1);
    $ra2 = deg2rad($ra2); $dec2 = deg2rad($dec2);
    $dra = $ra2 - $ra1; $ddec = $dec2 - $dec1;
    $a = sin($ddec/2)**2 + cos($dec1) * cos($dec2) * sin($dra/2)**2;
    return 2 * asin(sqrt($a)) * (180.0 / M_PI) * 3600.0;
}
```

**Acceptance criteria:**
- Returns only sources within `radius_arcsec` of the query point (Haversine-precise)
- Excludes sources from frames observed at or after `before_time`
- Missing `ra`, `dec`, or `radius_arcsec` → `400`
- Empty result → `{ "data": [] }`

---

## Task 9 — GET /api/v1/frames/covering ✅ DONE

**Goal:** Return frames whose FOV covered a given sky point before a given time.

**Steps:**

1. Add `FramesController::covering()`:
   - Validate required params: `ra`, `dec`, `before_time`
   - Bounding-box pre-filter on `frames`:
     ```sql
     WHERE obs_time < :before_time
       AND ra_center  BETWEEN :ra  - (fov_deg/2) AND :ra  + (fov_deg/2)
       AND dec_center BETWEEN :dec - (fov_deg/2) AND :dec + (fov_deg/2)
     ```
     Simplified pre-filter: use `MAX(fov_deg)/2` as a conservative bound, e.g. 5 degrees.
     Better: use `ra_center BETWEEN :ra - fov_deg AND :ra + fov_deg` (slightly wider).
   - For each candidate frame, check with Haversine:
     `haversineArcsec(ra, dec, frame.ra_center, frame.dec_center) <= frame.fov_deg/2 * 3600`
   - Return `200`:
     ```json
     {
       "data": [
         { "id": "38", "filename": "...", "obs_time": "...", "ra_center": ..., "dec_center": ..., "fov_deg": ... }
       ]
     }
     ```
2. Register route:
   ```
   $routes->get('api/v1/frames/covering', 'Api\V1\FramesController::covering');
   ```
   **Important:** Register this route BEFORE `(:num)` wildcard routes to avoid conflicts.

**Acceptance criteria:**
- Returns frames that actually cover the query point (Haversine check on `fov_deg/2`)
- `before_time` filter works correctly
- Missing `ra` or `dec` → `400`
- Empty result → `{ "data": [] }`

---

## Task 10 — Unified Error Handling & Response Format ✅ DONE

**Goal:** Ensure all error responses follow the standard format.

**Steps:**

1. Create `app/Controllers/BaseApiController.php` extending `BaseController`:
   - Helper methods: `respondCreated($data)`, `respondOk($data)`, `respondError($code, $message, $details = [])`
   - All API controllers extend `BaseApiController`

2. Override CI4's exception handler in `app/Config/Exceptions.php` (or use a filter):
   - Return JSON `{"error": "...", "details": {}}` for 404, 405, 500 instead of HTML pages

3. Standard responses:
   - `400`: `{"error": "Missing required fields", "details": {"field": "filename"}}`
   - `401`: `{"error": "Unauthorized", "details": {}}`
   - `404`: `{"error": "Frame not found", "details": {}}`
   - `422`: `{"error": "Validation failed", "details": {"field": "message"}}`
   - `500`: `{"error": "Internal server error", "details": {}}`

**Acceptance criteria:**
- All error responses are JSON with `error` and `details` keys
- No HTML error pages returned for any request to `/api/v1/*`
- Unknown routes under `/api/v1/` return `404` JSON

---

## Task 11 — Add PHP Service to docker-compose.yml ✅ DONE

**Goal:** Serve the CI4 application from Docker alongside the MariaDB container.

**Steps:**

1. Add a `php-api` service to `docker-compose.yml`:
   ```yaml
   php-api:
     image: php:8.2-apache
     container_name: observatory-php-api
     working_dir: /var/www/html
     volumes:
       - .:/var/www/html
     ports:
       - "8080:80"
     depends_on:
       - observatory-api
     environment:
       - CI_ENVIRONMENT=development
   ```
   Also install required PHP extensions: `pdo_mysql`, `mysqli`, `intl`.
   Use a custom `Dockerfile` for the PHP service if extension installs are needed.

2. Update `.env` database hostname to use the container name:
   ```
   database.default.hostname = observatory-api
   ```
   (Docker service name resolves inside the Docker network)

3. Verify:
   ```bash
   docker compose up -d
   curl http://localhost:8080/api/v1/frames/covering?ra=0&dec=0&before_time=2024-01-01T00:00:00Z \
     -H "X-API-Key: your-key"
   ```

**Acceptance criteria:**
- `docker compose up -d` starts both MariaDB and PHP services
- API is accessible at `http://localhost:8080/api/v1/`
- PHP container can connect to MariaDB using the Docker service name

---

## Task 12 — End-to-End Integration Test

**Goal:** Verify the full pipeline flow works correctly through the API.

**Steps:** Run the following sequence manually (or write a shell/PHP script):

1. **POST /api/v1/frames** — register a test frame, capture `id`
2. **POST /api/v1/frames/{id}/sources** — save 3 test sources
3. **POST /api/v1/frames/{id}/anomalies** — save 2 anomalies (1 UNKNOWN, 1 ASTEROID)
4. **GET /api/v1/sources/near** — query near one of the saved source coordinates, verify it appears
5. **GET /api/v1/frames/covering** — query the frame's center coordinates, verify the frame appears

All requests include `X-API-Key` header.

**Acceptance criteria:**
- All 5 calls return expected status codes and response bodies
- Source cone search returns the source saved in step 2
- Frames covering returns the frame registered in step 1
- Anomaly response shows `alerts: 1` (only UNKNOWN is alert-worthy; ASTEROID is not)

---

## Summary of Routes

```
POST   /api/v1/frames                    → FramesController::create
POST   /api/v1/frames/(:num)/sources     → FramesController::saveSources/$1
POST   /api/v1/frames/(:num)/anomalies   → FramesController::saveAnomalies/$1
GET    /api/v1/frames/covering           → FramesController::covering   (before wildcard)
GET    /api/v1/sources/near              → SourcesController::near
```

All routes are protected by the `ApiKeyFilter`.
