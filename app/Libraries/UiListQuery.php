<?php

namespace App\Libraries;

use CodeIgniter\HTTP\IncomingRequest;

/**
 * Sorting and pagination for the web UI's list pages, from the request's `sort`, `dir`, `page`
 * and `per_page`. Only whitelisted sort keys ever reach SQL: each maps to an expression the
 * controller wrote, never to anything from the request.
 *
 * Usage: build it, count the filtered rows, call paginate($total), then fetch perPage rows at
 * offset() ordered by orderBy(). The view links through sortUrl() / pageUrl() (partials/pager).
 */
final class UiListQuery
{
    public const PER_PAGE = [25, 50, 100, 200];

    public readonly string $sort;
    public readonly string $dir;
    public readonly int $perPage;
    public int $page;
    public int $pages = 1;
    public int $total = 0;

    private IncomingRequest $request;

    /**
     * @param array<string, string> $sortable  key => SQL expression to ORDER BY
     * @param list<string>          $descFirst keys whose first click sorts descending (times, counts)
     * @param string                $tiebreak  appended to every ORDER BY so pages never overlap
     * @param list<int>             $perPageOptions what the page-size select offers
     */
    public function __construct(
        private readonly array $sortable,
        string $defaultSort,
        string $defaultDir = 'desc',
        private readonly array $descFirst = [],
        private readonly string $tiebreak = 'id',
        int $defaultPerPage = 50,
        public readonly array $perPageOptions = self::PER_PAGE,
        ?IncomingRequest $request = null,
    ) {
        $this->request = $request ?? service('request');

        $sort       = (string) $this->request->getGet('sort');
        $this->sort = isset($sortable[$sort]) ? $sort : $defaultSort;

        $dir       = strtolower((string) $this->request->getGet('dir'));
        $this->dir = in_array($dir, ['asc', 'desc'], true) ? $dir : ($sort === $this->sort ? $this->firstDir($this->sort) : $defaultDir);

        $perPage       = (int) $this->request->getGet('per_page');
        $this->perPage = in_array($perPage, $perPageOptions, true) ? $perPage : $defaultPerPage;

        $this->page = max(1, (int) $this->request->getGet('page'));
    }

    /** Records the filtered row count and keeps the page within it. */
    public function paginate(int $total): self
    {
        $this->total = $total;
        $this->pages = max(1, (int) ceil($total / $this->perPage));
        $this->page  = min($this->page, $this->pages);

        return $this;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** The ORDER BY clause: the chosen column, then the tiebreak in the same direction. */
    public function orderBy(): string
    {
        $dir = strtoupper($this->dir);

        return $this->sortable[$this->sort] . ' ' . $dir . ', ' . $this->tiebreak . ' ' . $dir;
    }

    /** 1-based number of the first and last row shown. */
    public function from(): int
    {
        return $this->total === 0 ? 0 : $this->offset() + 1;
    }

    public function to(): int
    {
        return min($this->total, $this->offset() + $this->perPage);
    }

    public function isSorted(string $key): bool
    {
        return $this->sort === $key;
    }

    /** Sorting by a column: the active one flips direction, another starts at its first direction. */
    public function sortUrl(string $key): string
    {
        $dir = $this->sort === $key
            ? ($this->dir === 'asc' ? 'desc' : 'asc')
            : $this->firstDir($key);

        return $this->url(['sort' => $key, 'dir' => $dir, 'page' => null]);
    }

    public function pageUrl(int $page): string
    {
        return $this->url(['page' => $page > 1 ? $page : null]);
    }

    /**
     * The page numbers to link: the first, the last and two around the current one, with null
     * for each gap ("1 … 4 5 6 7 8 … 20").
     *
     * @return list<int|null>
     */
    public function window(): array
    {
        $keep = array_unique(array_filter(
            [1, $this->pages, ...range(max(1, $this->page - 2), min($this->pages, $this->page + 2))],
            fn (int $p): bool => $p >= 1 && $p <= $this->pages,
        ));
        sort($keep);

        $out  = [];
        $prev = 0;
        foreach ($keep as $p) {
            if ($p - $prev === 2) {
                $out[] = $p - 1; // a gap of one page: show it instead of "…"
            } elseif ($p - $prev > 2) {
                $out[] = null;
            }
            $out[] = $p;
            $prev  = $p;
        }

        return $out;
    }

    /**
     * The current query string's parameters other than the given ones — for the per-page form,
     * which must keep the filter and the sort as hidden fields.
     *
     * @return array<string, string>
     */
    public function keptParams(string ...$except): array
    {
        $params = array_diff_key((array) $this->request->getGet(), array_flip($except));

        return array_filter($params, static fn ($v): bool => is_string($v) && $v !== '');
    }

    private function firstDir(string $key): string
    {
        return in_array($key, $this->descFirst, true) ? 'desc' : 'asc';
    }

    /** @param array<string, scalar|null> $changes */
    private function url(array $changes): string
    {
        $params = array_merge((array) $this->request->getGet(), $changes);
        $params = array_filter($params, static fn ($v): bool => $v !== null && $v !== '');
        $path   = '/' . trim($this->request->getUri()->getRoutePath(), '/');

        return $path . ($params === [] ? '' : '?' . http_build_query($params));
    }
}
