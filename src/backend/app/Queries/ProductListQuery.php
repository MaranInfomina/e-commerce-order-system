<?php

namespace App\Queries;

use Illuminate\Database\Eloquent\Builder;

class ProductListQuery
{
    /**
     * The only sort values the API accepts, mapped to column and direction.
     * ProductIndexRequest validates against these keys, so this constant is
     * the single source of truth — adding a sort here exposes it in the API.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const SORTS = [
        'name' => ['name', 'asc'],
        '-name' => ['name', 'desc'],
        'price' => ['price_cents', 'asc'],
        '-price' => ['price_cents', 'desc'],
        'created_at' => ['created_at', 'asc'],
        '-created_at' => ['created_at', 'desc'],
    ];

    private const DEFAULT_SORT = '-created_at';

    /**
     * @param  array<string, mixed>  $filters
     */
    public function apply(Builder $query, array $filters): Builder
    {
        if (! empty($filters['search'])) {
            // Escape LIKE metacharacters in the user's term so a literal
            // "%" or "_" is matched literally instead of acting as a
            // wildcard — otherwise a single "%" or "_" search matches every
            // row. Postgres LIKE/ILIKE uses backslash as the default escape
            // character, so no ESCAPE clause is required.
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']);
            $term = '%'.$escaped.'%';

            $query->where(function (Builder $inner) use ($term) {
                $inner->where('name', 'ILIKE', $term)
                    ->orWhere('description', 'ILIKE', $term);
            });
        }

        if (! empty($filters['category'])) {
            $query->whereHas(
                'category',
                fn (Builder $inner) => $inner->where('slug', $filters['category'])
            );
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (isset($filters['min_price'])) {
            $query->where('price_cents', '>=', (int) $filters['min_price']);
        }

        if (isset($filters['max_price'])) {
            $query->where('price_cents', '<=', (int) $filters['max_price']);
        }

        // apply() is public and only guaranteed to see a validated 'sort'
        // when called through ProductIndexRequest — fall back rather than
        // risk an "Undefined array key" on an unvetted caller.
        $sortKey = $filters['sort'] ?? self::DEFAULT_SORT;
        [$column, $direction] = self::SORTS[$sortKey] ?? self::SORTS[self::DEFAULT_SORT];

        // Tie-break on id so pagination is stable when the sort column repeats.
        return $query->orderBy($column, $direction)->orderBy('id', 'asc');
    }
}
