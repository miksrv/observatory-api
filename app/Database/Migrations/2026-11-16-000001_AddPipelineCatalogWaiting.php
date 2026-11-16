<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Add `pipelines.catalog_waiting`: what a pipeline holds an ANALYZE item for while a catalog it
 * needs does not answer (observatory-pipeline 1.7.1+, `POST /pipelines/catalogs` `waiting`) —
 * JSON `{reason, catalogs, since, task_id, filename}`, NULL when it is not waiting. Shown on the
 * dashboard.
 */
class AddPipelineCatalogWaiting extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('pipelines', [
            'catalog_waiting' => [
                'type'  => 'TEXT',
                'null'  => true,
                'after' => 'catalog_checked_at',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('pipelines', 'catalog_waiting');
    }
}
