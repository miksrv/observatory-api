<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Add `pipelines.version`: the release the pipeline reports in its `X-Pipeline-Version` request
 * header (observatory-pipeline 1.4.0+), recorded with the heartbeat and shown on the dashboard.
 * NULL until a pipeline sends it.
 */
class AddPipelineVersion extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('pipelines', [
            'version' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => true,
                'after'      => 'last_ip',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('pipelines', 'version');
    }
}
