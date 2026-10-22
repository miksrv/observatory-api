<?php

namespace App\Controllers\Web;

use App\Libraries\UiListQuery;
use App\Models\AnomalyModel;
use App\Models\AnomalyReviewModel;
use App\Models\SourceChartModel;
use App\Models\SourceObservationModel;
use App\Models\TaskModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Web UI page: "Anomalies" — anomalies grouped by source, joined with their frame
 * (object/filename/obs_time) and source (catalog identity) for context. Each row in the list
 * represents one source with all its anomalies aggregated together; the groups are counted,
 * sorted and paged in SQL.
 *
 * The list shows unreviewed anomalies by default (`review=new`): once the operator has looked at
 * one and marked it "nothing interesting" (review(), AnomalyReviewModel) it moves to the
 * `review=done` tab, with the note.
 */
class AnomaliesController extends BaseWebController
{
    use RedirectsToCreatedTasks;

    public function index(): ResponseInterface
    {
        $filters = [
            'anomaly_type' => trim((string) ($this->request->getGet('anomaly_type') ?? '')),
            'is_alert'     => trim((string) ($this->request->getGet('is_alert') ?? '')),
            'object'       => trim((string) ($this->request->getGet('object') ?? '')),
            'frame_id'     => trim((string) ($this->request->getGet('frame_id') ?? '')),
            'source_id'    => trim((string) ($this->request->getGet('source_id') ?? '')),
            'review'       => trim((string) ($this->request->getGet('review') ?? '')),
        ];
        if (! in_array($filters['review'], ['new', 'done', 'all'], true)) {
            $filters['review'] = 'new';
        }

        // One row per source; an anomaly without a source is a group of its own (keyed by its id).
        // Grouping, counting and sorting happen in SQL so the pages cover every anomaly.
        $groupKey = 'COALESCE(anomalies.source_id, anomalies.id)';

        $list = new UiListQuery(
            [
                'designation' => 'designation',
                'object'      => 'object',
                'count'       => 'anomaly_count',
                'alert'       => 'has_alert',
                'last_obs'    => 'last_obs',
            ],
            'last_obs',
            'desc',
            ['count', 'alert', 'last_obs'],
            'group_key',
        );

        $db = db_connect();

        $total = (int) $db->query(
            'SELECT COUNT(*) AS n FROM ('
            . $this->filtered($db->table('anomalies')->select($groupKey . ' AS group_key', false), $filters)
                ->groupBy('group_key')
                ->getCompiledSelect()
            . ') AS g'
        )->getRow()->n;
        $list->paginate($total);

        $page = $this->filtered($db->table('anomalies'), $filters)
            ->select($groupKey . ' AS group_key', false)
            ->select('COUNT(*) AS anomaly_count, MAX(anomalies.is_alert) AS has_alert', false)
            ->select('MAX(frames.obs_time) AS last_obs, MAX(frames.object) AS object', false)
            ->select('COALESCE(MAX(anomalies.mpc_designation), MAX(sources.catalog_id)) AS designation', false)
            ->groupBy('group_key')
            ->orderBy($list->orderBy(), '', false)
            ->limit($list->perPage, $list->offset())
            ->get()->getResultArray();

        $keys = array_column($page, 'group_key');

        // The page's anomalies themselves, newest first, under the same filter.
        $anomalies = $keys === [] ? [] : $this->filtered((new AnomalyModel())->builder(), $filters)
            ->select(
                'anomalies.*, frames.object AS object, frames.filename AS filename, '
                . 'frames.obs_time AS obs_time, sources.catalog_name AS catalog_name, '
                . 'sources.catalog_id AS catalog_id, anomaly_reviews.id AS review_id, '
                . 'anomaly_reviews.note AS review_note, ' . $groupKey . ' AS group_key',
                false
            )
            // The key is an expression, which whereIn() can't quote as a column; the values are
            // escaped here instead.
            ->where($groupKey . ' IN (' . implode(', ', array_map([$db, 'escape'], $keys)) . ')', null, false)
            ->orderBy('frames.obs_time', 'DESC')
            ->get()->getResultArray();

        // Groups in the page's order; each collects its anomalies.
        $groups = array_fill_keys($keys, null);

        foreach ($anomalies as $a) {
            $key = $a['group_key'];
            if ($groups[$key] === null) {
                $groups[$key] = [
                    'source_id'    => $a['source_id'],
                    'catalog_name' => $a['catalog_name'] ?? null,
                    'catalog_id'   => $a['catalog_id'] ?? null,
                    'object'       => $a['object'] ?? null,
                    'anomaly_ids'  => [],
                    'types'        => [],
                    // anomaly_ids grouped by their own anomaly_type — a source classified with
                    // more than one type over its lifetime (see createTask()'s docblock) needs
                    // its GENERATE_CHARTS task built per-type, not per-group, so each type's own
                    // chart (style) actually gets (re)generated instead of just one of them.
                    'ids_by_type'  => [],
                    'has_alert'    => false,
                    'first_obs'    => $a['obs_time'],
                    'last_obs'     => $a['obs_time'],
                    'mpc_designation' => null,
                    'reviewed'     => 0,
                    'review_notes' => [],
                    // The newest anomaly's position (rows come newest first).
                    'ra'           => $a['ra'],
                    'dec'          => $a['dec'],
                ];
            }

            $g = &$groups[$key];
            $g['anomaly_ids'][] = $a['id'];
            $g['types'][]       = $a['anomaly_type'];
            $g['ids_by_type'][$a['anomaly_type']][] = $a['id'];
            $g['has_alert']     = $g['has_alert'] || (bool) $a['is_alert'];
            if ($a['review_id'] !== null) {
                $g['reviewed']++;
                if ($a['review_note'] !== null && $a['review_note'] !== '') {
                    $g['review_notes'][] = $a['review_note'];
                }
            }

            if ($a['obs_time'] && ($g['first_obs'] === null || $a['obs_time'] < $g['first_obs'])) {
                $g['first_obs'] = $a['obs_time'];
            }
            if ($a['obs_time'] && ($g['last_obs'] === null || $a['obs_time'] > $g['last_obs'])) {
                $g['last_obs'] = $a['obs_time'];
            }
            // The newest MPC designation wins.
            if ($g['mpc_designation'] === null && ! empty($a['mpc_designation'])) {
                $g['mpc_designation'] = $a['mpc_designation'];
            }
            unset($g);
        }

        // Deduplicate types within each group, and resolve a single display
        // designation: prefer the MPC designation (solar-system objects),
        // falling back to the source's own catalog_id (e.g. a Simbad main_id
        // like "V* BE UMa") for catalog-matched but non-MPC anomaly types
        // (VARIABLE_STAR, BINARY_STAR, SUPERNOVA_CANDIDATE) — mpc_designation
        // is only ever set on MPC matches (see observatory-pipeline's
        // modules/anomaly_detector.py), so without this fallback those types
        // always carried designation=null into the GENERATE_CHARTS task
        // payload below, even though the same source's catalog identity is
        // already visible right here via the sources join.
        $groups = array_values(array_filter($groups));
        foreach ($groups as &$g) {
            $g['types']        = array_values(array_unique($g['types']));
            $g['review_notes'] = array_values(array_unique($g['review_notes']));
            $g['designation'] = $g['mpc_designation'] ?: $g['catalog_id'];
        }
        unset($g);

        return $this->response->setBody(view('web/anomalies_index', [
            'groups'       => $groups,
            'anomalyTypes' => AnomalyModel::ALLOWED_TYPES,
            'filters'      => $filters,
            'list'         => $list,
        ]));
    }

    /**
     * The anomalies list's filter on a builder over `anomalies`, with the frame and source joined
     * (the filter and the grouped columns read both).
     *
     * @param array<string, string> $filters
     */
    private function filtered(BaseBuilder $builder, array $filters): BaseBuilder
    {
        $builder->join('frames', 'frames.id = anomalies.frame_id', 'left')
            ->join('sources', 'sources.id = anomalies.source_id', 'left');
        AnomalyReviewModel::joinReviews(
            $builder,
            match ($filters['review'] ?? 'all') {
                'new'   => false,
                'done'  => true,
                default => null,
            }
        );

        if ($filters['anomaly_type'] !== '') {
            $builder->where('anomalies.anomaly_type', $filters['anomaly_type']);
        }
        if ($filters['is_alert'] !== '') {
            $builder->where('anomalies.is_alert', (int) $filters['is_alert']);
        }
        if ($filters['object'] !== '') {
            $builder->where('frames.object', $filters['object']);
        }
        if ($filters['frame_id'] !== '') {
            $builder->where('anomalies.frame_id', $filters['frame_id']);
        }
        if ($filters['source_id'] !== '') {
            $builder->where('anomalies.source_id', $filters['source_id']);
        }

        return $builder;
    }

    /**
     * POST /ui/anomalies/generate-charts — create a GENERATE_CHARTS task from selected groups.
     *
     * Builds one task item PER UNIQUE anomaly_type within each selected group — not one item per
     * group. A source classified with more than one anomaly_type over its lifetime (e.g. an
     * uncatalogued mover first seen with no history as UNKNOWN, then MOVING_UNKNOWN once it had
     * moved) needs both its "track" and "stamp_strip" charts (re)generated, and
     * observatory-pipeline's finder_chart.py renders one chart per distinct style, keyed by the
     * anomaly_type each GENERATE_CHARTS item carries (see that module's docblock and
     * SourceChartModel's, in the pipeline repo's CLAUDE.md). Submitting only one item per group —
     * the previous behavior, picking a single arbitrary type — silently generated just one of the
     * two charts (real incident, 2026-08-11: source_id 6a7be36b4d7578.98132403, 12 MOVING_UNKNOWN
     * + 1 UNKNOWN anomalies, only ever produced a single chart).
     *
     * Each item's `payload.anomaly_ids` is scoped to just that type's own anomaly ids, for
     * traceability, rather than the whole group's flattened list.
     *
     * Items are split into one task per pipeline that can draw them — the owner of each source's
     * latest frame (SourceObservationModel::getChartPipelineForSources()).
     */
    public function createTask(): ResponseInterface
    {
        $groupsData = $this->collectSelectedGroups();

        if (count($groupsData) === 0) {
            return redirect()->back()->with('error', 'Выберите хотя бы одну группу аномалий.');
        }

        // Filter out groups without source_id.
        $groupsData = array_filter($groupsData, static fn ($g) => ! empty($g['source_id']));

        if (count($groupsData) === 0) {
            return redirect()->back()->with('error', 'Ни одна из выбранных групп не привязана к источнику (source_id).');
        }

        $owners = (new SourceObservationModel())
            ->getChartPipelineForSources(array_values(array_unique(array_column($groupsData, 'source_id'))));

        $itemsByPipeline = [];
        $itemCount       = 0;
        foreach ($groupsData as $g) {
            foreach ($g['ids_by_type'] as $anomalyType => $anomalyIds) {
                $itemCount++;
                $itemsByPipeline[$owners[$g['source_id']] ?? ''][] = [
                    'source_id' => $g['source_id'],
                    'payload'   => [
                        'anomaly_type' => $anomalyType,
                        'designation'  => $g['designation'],
                        'anomaly_ids'  => $anomalyIds,
                    ],
                ];
            }
        }

        $taskModel = new TaskModel();
        $created   = $taskModel->createPerPipeline('GENERATE_CHARTS', $itemsByPipeline);

        if ($created === false) {
            return redirect()->back()->with('error', 'Не удалось создать задачу: ' . implode(', ', $taskModel->errors()));
        }

        // The item count can exceed count($groupsData) — a group with both MOVING_UNKNOWN and
        // UNKNOWN anomalies contributes 2 items (one chart style each) from a single source — so
        // the message reports item and source counts separately rather than a single ambiguous
        // number.
        return $this->redirectToCreatedTasks(
            $created,
            'GENERATE_CHARTS',
            "Всего {$itemCount} чарт(ов) для " . count($groupsData) . ' источник(ов).',
        );
    }

    /**
     * POST /ui/anomalies/delete — delete selected groups' anomalies and any associated
     * source_charts (both DB rows and chart image files on disk).
     */
    public function delete(): ResponseInterface
    {
        $groupsData = $this->collectSelectedGroups();

        if (count($groupsData) === 0) {
            return redirect()->back()->with('error', 'Выберите хотя бы одну группу аномалий для удаления.');
        }

        // Flatten all anomaly IDs from selected groups.
        $anomalyIds = [];
        foreach ($groupsData as $g) {
            $anomalyIds = array_merge($anomalyIds, $g['anomaly_ids']);
        }
        $anomalyIds = array_unique($anomalyIds);

        $anomalyModel = new AnomalyModel();
        $anomalies    = $anomalyModel->whereIn('id', $anomalyIds)->findAll();

        if (count($anomalies) === 0) {
            return redirect()->back()->with('error', 'Выбранные аномалии не найдены (устарели?).');
        }

        // Collect unique source_ids that have charts to clean up.
        $sourceIds = array_values(array_unique(array_filter(
            array_column($anomalies, 'source_id')
        )));

        $deletedCharts = 0;

        if (! empty($sourceIds)) {
            $chartModel = new SourceChartModel();
            // A source_id may now have more than one chart row — one per style (see
            // SourceChartModel's class docblock) — so this can return several rows per
            // source_id; each is deleted individually below, by its own style-suffixed file.
            $charts     = $chartModel->whereIn('source_id', $sourceIds)->findAll();

            foreach ($charts as $chart) {
                // Delete chart image file from disk — a GIF style's file is .gif (see CLAUDE.md).
                $ext      = in_array($chart['style'], SourceChartModel::GIF_STYLES, true) ? 'gif' : 'png';
                $filePath = WRITEPATH . 'uploads/charts/' . $chart['source_id'] . '_' . $chart['style'] . '.' . $ext;
                if (is_file($filePath)) {
                    unlink($filePath);
                }

                // Also clean up a pre-migration, un-suffixed file left over from before
                // 2026-08-11-000001_SourceChartsUniqueByStyle.php, if one still exists.
                $legacyPath = WRITEPATH . 'uploads/charts/' . $chart['source_id'] . '.png';
                if (is_file($legacyPath)) {
                    unlink($legacyPath);
                }
            }

            $deletedCharts = count($charts);

            if ($deletedCharts > 0) {
                $chartModel->whereIn('source_id', $sourceIds)->delete();
            }
        }

        // Delete the anomalies themselves.
        $anomalyModel->whereIn('id', $anomalyIds)->delete();

        $msg = 'Удалено аномалий: ' . count($anomalies);
        if ($deletedCharts > 0) {
            $msg .= ', чартов: ' . $deletedCharts;
        }

        return redirect()->back()->with('success', $msg . '.');
    }

    /**
     * POST /ui/anomalies/review — mark the selected groups' anomalies "looked at, nothing
     * interesting", with an optional note. They leave the default (unreviewed) list and the
     * dashboard's counts, and the mark survives DETECT_ANOMALIES re-runs (AnomalyReviewModel).
     */
    public function review(): ResponseInterface
    {
        $anomalies = $this->selectedAnomalies();
        if ($anomalies === []) {
            return redirect()->back()->with('error', 'Выберите хотя бы одну группу аномалий.');
        }

        $note   = (string) ($this->request->getPost('note') ?? '');
        $marked = (new AnomalyReviewModel())->markReviewed($anomalies, $note);
        $msg    = 'Отмечено проверенными: ' . $marked;
        $skipped = count($anomalies) - $marked;
        if ($skipped > 0) {
            $msg .= ' (без источника отметить нельзя: ' . $skipped . ')';
        }

        return redirect()->back()->with('success', $msg . '.');
    }

    /**
     * POST /ui/anomalies/unreview — take the review mark off the selected groups' anomalies.
     */
    public function unreview(): ResponseInterface
    {
        $anomalies = $this->selectedAnomalies();
        if ($anomalies === []) {
            return redirect()->back()->with('error', 'Выберите хотя бы одну группу аномалий.');
        }

        $removed = (new AnomalyReviewModel())->unmarkReviewed($anomalies);

        return redirect()->back()->with('success', 'Снята отметка «проверено»: ' . $removed . '.');
    }

    /**
     * The `anomalies` rows behind the selected groups.
     *
     * @return list<array<string, mixed>>
     */
    private function selectedAnomalies(): array
    {
        $ids = [];
        foreach ($this->collectSelectedGroups() as $g) {
            $ids = array_merge($ids, $g['anomaly_ids']);
        }
        $ids = array_values(array_unique(array_filter($ids, 'is_string')));

        return $ids === [] ? [] : (new AnomalyModel())->whereIn('id', $ids)->findAll();
    }

    /**
     * Extract selected groups from POST data.
     *
     * Each group is submitted as group_data[] with JSON-encoded
     * {source_id, ids_by_type: {anomaly_type: [id, ...], ...}, designation} — see
     * anomalies_index.php's checkbox value and createTask()'s docblock for why anomaly_ids are
     * grouped by anomaly_type here rather than a single flat list.
     *
     * @return array<array{source_id: ?string, ids_by_type: array<string, string[]>, anomaly_ids: string[], designation: ?string}>
     */
    private function collectSelectedGroups(): array
    {
        $raw = (array) ($this->request->getPost('group_data') ?? []);

        $groups = [];
        foreach ($raw as $json) {
            if (! is_string($json) || $json === '') {
                continue;
            }
            $decoded = json_decode($json, true);
            if (! is_array($decoded) || empty($decoded['ids_by_type']) || ! is_array($decoded['ids_by_type'])) {
                continue;
            }

            $idsByType  = [];
            $anomalyIds = [];
            foreach ($decoded['ids_by_type'] as $type => $ids) {
                if (! is_string($type) || $type === '' || ! is_array($ids) || count($ids) === 0) {
                    continue;
                }
                $idsByType[$type] = array_values($ids);
                $anomalyIds       = array_merge($anomalyIds, $ids);
            }

            if (empty($idsByType)) {
                continue;
            }

            $groups[] = [
                'source_id'   => $decoded['source_id'] ?? null,
                'ids_by_type' => $idsByType,
                'anomaly_ids' => $anomalyIds,
                'designation' => $decoded['designation'] ?? null,
            ];
        }

        return $groups;
    }
}
