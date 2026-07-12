<?php
declare(strict_types=1);
namespace App\Controllers\Web\Admin;

use App\Http\Request;

/**
 * Einheitliche Parametrisierung von Verwaltungslisten (GameAdmin_Concept.md,
 * Phase 0): parst Suchtext, Seite, Seitengröße und Sortierung aus dem Request —
 * Sortierspalten werden gegen eine Whitelist geprüft (kein SQL-Injection über
 * ORDER BY). Wiederverwendbar für User-/Fahrten-/Review-Listen.
 */
final class AdminListQuery
{
    private function __construct(
        public readonly string $q,
        public readonly int $page,
        public readonly int $perPage,
        public readonly string $sort,
        public readonly string $dir,
        public readonly int $offset,
    ) {}

    /**
     * @param list<string> $allowedSorts erlaubte Sortierspalten (Whitelist)
     */
    public static function fromRequest(
        Request $req,
        array $allowedSorts,
        string $defaultSort,
        int $defaultPerPage = 50,
        int $maxPerPage = 200,
    ): self {
        // Verwaltungslisten sind GET → Parameter kommen aus der Query.
        $get = static fn(string $k, $d) => $req->query[$k] ?? $d;

        $q = trim((string)$get('q', ''));

        $page = max(1, (int)$get('page', 1));
        $perPage = (int)$get('per_page', $defaultPerPage);
        $perPage = max(1, min($maxPerPage, $perPage));

        $sort = (string)$get('sort', $defaultSort);
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = $defaultSort;
        }
        $dir = strtolower((string)$get('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        return new self($q, $page, $perPage, $sort, $dir, ($page - 1) * $perPage);
    }

    /** SQL-Fragment `ORDER BY <sort> <dir>` — sort ist bereits whitelistet. */
    public function orderBy(): string
    {
        return "ORDER BY {$this->sort} {$this->dir}";
    }

    /** Baut einen Query-String mit überschriebenen Parametern (für Links/Sort-Header). */
    public function withParams(array $overrides = []): string
    {
        $params = array_merge([
            'q' => $this->q, 'page' => $this->page, 'per_page' => $this->perPage,
            'sort' => $this->sort, 'dir' => $this->dir,
        ], $overrides);
        $params = array_filter($params, static fn($v) => $v !== '' && $v !== null);
        return http_build_query($params);
    }

    public function hasMore(int $rowCount): bool
    {
        return $rowCount >= $this->perPage;
    }
}
