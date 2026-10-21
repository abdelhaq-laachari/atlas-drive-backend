<?php

namespace App\Models\Concerns;

/**
 * Gives each new row the next number of its agency (1, 2, 3...), shown as a code like V-007 or C-012.
 * Numbers are never reused (archived rows count too). Use after BelongsToAgency.
 *
 * The model defines:
 * - numberColumn(): string  e.g. 'fleet_number'
 * - numberPrefix(): string  e.g. 'V-'
 * The table needs: $table->unsignedInteger('<column>'); $table->unique(['agency_id', '<column>']);
 */
trait NumberedPerAgency
{
    public static function bootNumberedPerAgency(): void
    {
        // Runs after BelongsToAgency has set agency_id; the unique index guards against duplicates.
        static::creating(function (self $model) {
            $column = $model->numberColumn();

            $model->{$column} ??= static::withoutGlobalScopes()
                ->where('agency_id', $model->agency_id)
                ->max($column) + 1;
        });
    }

    /**
     * Code shown to people, e.g. V-007 / C-012.
     */
    public function code(): string
    {
        return $this->numberPrefix().str_pad((string) $this->{$this->numberColumn()}, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Turns "V-007", "v7" or "7" into 7 (null if it is not a code).
     */
    public static function numberFromCode(string $search, string $prefix): ?int
    {
        $letter = preg_quote(rtrim($prefix, '-'), '/');

        return preg_match('/^(?:'.$letter.'-?)?0*(\d+)$/i', trim($search), $matches) ? (int) $matches[1] : null;
    }
}
