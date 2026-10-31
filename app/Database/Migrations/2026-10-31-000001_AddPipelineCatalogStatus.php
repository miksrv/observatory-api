<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Add `pipelines.catalog_status` / `catalog_checked_at`: the reachability of every online catalog
 * a pipeline uses (Simbad, Gaia, VizieR, SkyBot, JPL, ...), as it last reported it with
 * `POST /pipelines/catalogs` (observatory-pipeline 1.6.3+). `catalog_status` is the reported list
 * as JSON; both are NULL until a pipeline reports. Shown on the dashboard.
 */
class AddPipelineCatalogStatus extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('pipelines', [
            'catalog_status' => [
                'type'  => 'TEXT',
                'null'  => true,
                'after' => 'version',
            ],
            'catalog_checked_at' => [
                'type'  => 'DATETIME',
                'null'  => true,
                'after' => 'catalog_status',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('pipelines', ['catalog_status', 'catalog_checked_at']);
    }
}
