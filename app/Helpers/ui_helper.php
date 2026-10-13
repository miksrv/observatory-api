<?php

/**
 * View helpers for the operator web UI (app/Views/web/*): icons, time and number formatting, the
 * status chips — one place, so every page renders the same thing the same way.
 * Loaded by App\Controllers\Web\BaseWebController.
 */

if (! function_exists('ui_icon')) {
    /**
     * An inline stroke icon (24-unit grid, currentColor). Unknown names render nothing.
     */
    function ui_icon(string $name, int $size = 16, string $class = ''): string
    {
        static $paths = [
            'overview'  => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
            'frames'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="12" cy="12" r="4"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3"/>',
            'sources'   => '<path d="M12 3l1.9 5.6L19.5 9l-4.5 3.6 1.6 5.7L12 15l-4.6 3.3L9 12.6 4.5 9l5.6-.4z"/>',
            'anomalies' => '<path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0-18 0"/><path d="M12 12l6-6"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
            'charts'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 16l5-5 4 4 3-3 6 6"/><circle cx="15.5" cy="8.5" r="1.5"/>',
            'tasks'     => '<path d="M9 6h11M9 12h11M9 18h11"/><path d="M3.5 6l1 1 2-2M3.5 12l1 1 2-2"/><circle cx="5" cy="18" r="1.2"/>',
            'settings'  => '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
            'logout'    => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 17l-5-5 5-5M5 12h11"/>',
            'sun'       => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
            'moon'      => '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/>',
            'eye'       => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
            'menu'      => '<path d="M4 6h16M4 12h16M4 18h16"/>',
            'panel'     => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/>',
            'x'         => '<path d="M18 6L6 18M6 6l12 12"/>',
            'check'     => '<path d="M5 12.5l4.5 4.5L19 7"/>',
            'search'    => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
            'external'  => '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
            'trash'     => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
            'play'      => '<path d="M7 4.5v15l12-7.5z"/>',
            'refresh'   => '<path d="M20 11a8 8 0 0 0-14.3-4.9L4 8M4 4v4h4M4 13a8 8 0 0 0 14.3 4.9L20 16M20 20v-4h-4"/>',
            'merge'     => '<circle cx="6" cy="5" r="2"/><circle cx="6" cy="19" r="2"/><circle cx="18" cy="12" r="2"/><path d="M6 7v10M6 7c0 4 4 5 10 5"/>',
            'chevron'   => '<path d="M9 6l6 6-6 6"/>',
            'back'      => '<path d="M15 6l-6 6 6 6"/>',
            'alert'     => '<path d="M12 3l9.5 17h-19z"/><path d="M12 10v4M12 17.5v.01"/>',
            'info'      => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 7.5v.01"/>',
            'lock'      => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
            'server'    => '<rect x="3" y="4" width="18" height="7" rx="1.5"/><rect x="3" y="13" width="18" height="7" rx="1.5"/><path d="M7 7.5h.01M7 16.5h.01"/>',
            'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'chart'     => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
            'crosshair' => '<circle cx="12" cy="12" r="8"/><path d="M12 2v5M12 17v5M2 12h5M17 12h5"/>',
            'save'      => '<path d="M5 3h11l3 3v13a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M7 3v5h8V3M7 21v-7h10v7"/>',
            'stop'      => '<rect x="6" y="6" width="12" height="12" rx="1.5"/>',
            'maximize'  => '<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/>',
            'sort'      => '<path d="M8 10l4-4 4 4M8 14l4 4 4-4"/>',
            'sort-asc'  => '<path d="M8 14l4-4 4 4"/>',
            'sort-desc' => '<path d="M8 10l4 4 4-4"/>',
        ];

        if (! isset($paths[$name])) {
            return '';
        }

        return '<svg class="icon' . ($class !== '' ? ' ' . esc($class, 'attr') : '') . '" width="' . $size . '" height="' . $size
            . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"'
            . ' stroke-linejoin="round" aria-hidden="true">' . $paths[$name] . '</svg>';
    }
}

if (! function_exists('ui_ts')) {
    /** A UTC `Y-m-d H:i:s` column value as a Unix timestamp, or null. */
    function ui_ts(?string $utc): ?int
    {
        if ($utc === null || $utc === '') {
            return null;
        }

        $ts = strtotime($utc . ' UTC');

        return $ts === false ? null : $ts;
    }
}

if (! function_exists('ui_time')) {
    /** A UTC column value formatted (UTC), or an em dash. */
    function ui_time(?string $utc, string $format = 'Y-m-d H:i'): string
    {
        $ts = ui_ts($utc);

        return $ts === null ? '—' : gmdate($format, $ts);
    }
}

if (! function_exists('ui_ago')) {
    /** "5 мин назад" for a UTC column value; `$never` when there is none. */
    function ui_ago(?string $utc, string $never = 'никогда'): string
    {
        $ts = ui_ts($utc);

        if ($ts === null) {
            return $never;
        }

        $ago = max(0, time() - $ts);

        return match (true) {
            $ago < 45     => 'только что',
            $ago < 3600   => max(1, intdiv($ago, 60)) . ' мин назад',
            $ago < 86400  => intdiv($ago, 3600) . ' ч назад',
            $ago < 86400 * 30 => intdiv($ago, 86400) . ' д назад',
            default       => gmdate('Y-m-d', $ts),
        };
    }
}

if (! function_exists('ui_when')) {
    /** `<time>` showing ui_ago(), the exact UTC time on hover. */
    function ui_when(?string $utc, string $never = '—'): string
    {
        $ts = ui_ts($utc);

        if ($ts === null) {
            return '<span class="dim">' . esc($never) . '</span>';
        }

        return '<time datetime="' . gmdate('c', $ts) . '" title="' . gmdate('Y-m-d H:i:s', $ts) . ' UTC">'
            . esc(ui_ago($utc)) . '</time>';
    }
}

if (! function_exists('ui_num')) {
    /** A nullable number with fixed decimals, or an em dash. */
    function ui_num(mixed $value, int $decimals = 0): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return number_format((float) $value, $decimals, '.', ' ');
    }
}

if (! function_exists('ui_value')) {
    /** A decoded JSON value as text: scalars as they are, null / booleans as literals, the rest as JSON. */
    function ui_value(mixed $value): string
    {
        return match (true) {
            $value === null  => 'null',
            is_bool($value)  => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default          => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }
}

if (! function_exists('ui_plural')) {
    /** Russian plural: ui_plural(5, 'кадр', 'кадра', 'кадров') → "кадров". */
    function ui_plural(int $n, string $one, string $few, string $many): string
    {
        $n10  = $n % 10;
        $n100 = $n % 100;

        return match (true) {
            $n10 === 1 && $n100 !== 11                              => $one,
            $n10 >= 2 && $n10 <= 4 && ($n100 < 12 || $n100 > 14)   => $few,
            default                                                => $many,
        };
    }
}

if (! function_exists('ui_chip')) {
    /** A small coloured label. Tones: green, amber, red, blue, violet, cyan, gray. */
    function ui_chip(string $text, string $tone = 'gray', string $title = '', bool $dot = false): string
    {
        return '<span class="chip ' . esc($tone, 'attr') . ($dot ? ' dot' : '') . '"'
            . ($title !== '' ? ' title="' . esc($title, 'attr') . '"' : '') . '>' . esc($text) . '</span>';
    }
}

if (! function_exists('ui_task_status')) {
    /** A task / task item status (TaskModel::STATUSES, plus items' DONE) as a chip. */
    function ui_task_status(string $status): string
    {
        [$label, $tone] = match ($status) {
            'PENDING'   => ['в очереди', 'amber'],
            'RUNNING'   => ['выполняется', 'blue'],
            'COMPLETED', 'DONE' => ['готово', 'green'],
            'FAILED'    => ['ошибка', 'red'],
            'CANCELLED' => ['отменена', 'gray'],
            default     => [strtolower($status), 'gray'],
        };

        return '<span class="chip ' . $tone . ' dot' . ($status === 'RUNNING' ? ' pulse' : '') . '" title="' . esc($status, 'attr') . '">'
            . esc($label) . '</span>';
    }
}

if (! function_exists('ui_task_status_label')) {
    function ui_task_status_label(string $status): string
    {
        return match ($status) {
            'PENDING'   => 'В очереди',
            'RUNNING'   => 'Выполняются',
            'COMPLETED' => 'Готовы',
            'FAILED'    => 'С ошибкой',
            'CANCELLED' => 'Отменены',
            default     => $status,
        };
    }
}

if (! function_exists('ui_task_type_hint')) {
    /** What a task type does, in one short phrase (for help text and tooltips). */
    function ui_task_type_hint(string $type): string
    {
        return match ($type) {
            'ANALYZE'               => 'Полный разбор файла с начала: астрометрия, фотометрия, аномалии.',
            'DETECT_ANOMALIES'      => 'Повторный поиск аномалий по уже сохранённым данным кадра.',
            'GENERATE_CHARTS'       => 'Построить карты-трекеры и стрипы для источника.',
            'PREVIEW_CATALOG_MATCH' => 'Диагностика: сопоставление с каталогом без регистрации кадра.',
            'DELETE_FRAME'          => 'Убрать файл в FITS_REJECTED и удалить кадр со всеми данными.',
            'RESTART'               => 'Перезапуск pipeline с новыми настройками.',
            default                 => '',
        };
    }
}

if (! function_exists('ui_qc')) {
    /** A frame's quality_flag as a chip. */
    function ui_qc(?string $flag): string
    {
        if ($flag === null || $flag === '') {
            return '<span class="dim">—</span>';
        }

        $tone = match ($flag) {
            'OK'  => 'green',
            'BAD' => 'red',
            default => 'amber',
        };

        return ui_chip($flag, $tone);
    }
}

if (! function_exists('ui_pipeline_status')) {
    /** [css tone, label] for PipelineModel's online / stale / offline / revoked. */
    function ui_pipeline_status(string $status): array
    {
        return match ($status) {
            'online'  => ['green', 'на связи'],
            'stale'   => ['amber', 'не отвечает'],
            'revoked' => ['gray', 'ключ отозван'],
            default   => ['red', 'не на связи'],
        };
    }
}

if (! function_exists('ui_aladin')) {
    /** Aladin Lite (DSS2 colour) centred on a position. */
    function ui_aladin(mixed $ra, mixed $dec, float $fov = 0.25): string
    {
        return 'https://aladin.cds.unistra.fr/AladinLite/?target='
            . rawurlencode(number_format((float) $ra, 6, '.', '') . ' ' . number_format((float) $dec, 6, '.', ''))
            . '&fov=' . $fov . '&survey=P%2FDSS2%2Fcolor';
    }
}

if (! function_exists('ui_query')) {
    /**
     * The current page's URL with some query parameters changed (null removes one) — for filter
     * tabs and "clear" links that keep the rest of the filter.
     *
     * @param array<string, scalar|null> $changes
     */
    function ui_query(array $changes): string
    {
        $request = service('request');
        $params  = array_merge((array) $request->getGet(), $changes);
        $params  = array_filter($params, static fn ($v): bool => $v !== null && $v !== '');
        $path    = '/' . trim($request->getUri()->getRoutePath(), '/');

        return $path . ($params === [] ? '' : '?' . http_build_query($params));
    }
}

if (! function_exists('ui_th')) {
    /**
     * A sortable table header: links to sorting by `$key` (UiListQuery::sortUrl()), marked with
     * the direction when it's the current sort.
     */
    function ui_th(\App\Libraries\UiListQuery $list, string $key, string $label, string $class = '', string $title = ''): string
    {
        $active = $list->isSorted($key);
        $arrow  = $active ? ui_icon($list->dir === 'asc' ? 'sort-asc' : 'sort-desc', 12) : ui_icon('sort', 12);
        $aria   = $active ? ' aria-sort="' . ($list->dir === 'asc' ? 'ascending' : 'descending') . '"' : '';

        return '<th class="' . esc(trim($class . ' sortable'), 'attr') . '"' . $aria
            . ($title !== '' ? ' title="' . esc($title, 'attr') . '"' : '') . '>'
            . '<a href="' . esc($list->sortUrl($key), 'attr') . '" class="' . ($active ? 'active' : '') . '">'
            . esc($label) . $arrow . '</a></th>';
    }
}

if (! function_exists('ui_asset')) {
    /** A file under public/assets with its mtime as a cache-buster. */
    function ui_asset(string $path): string
    {
        $file = FCPATH . 'assets/' . $path;

        return '/assets/' . $path . (is_file($file) ? '?v=' . filemtime($file) : '');
    }
}
