<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Facades\Auth;

/**
 * Add to a tenant model to record footprints automatically (created / updated...).
 *
 * The model may define:
 * - activityLabel(): string  — readable name frozen in the log, e.g. "V-011 Dacia Logan"
 * - activityAction(array $changes): string — a clearer action than "updated", e.g. "archived"
 * - $activityIgnored — extra fields never logged (timestamps are always ignored)
 * - $activityMasked  — sensitive fields logged as "changed" without their values (e.g. CIN number)
 */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(function (self $model) {
            $model->logActivity('created', $model->activityChanges(
                array_map(fn () => null, $model->getAttributes()),
                $model->getAttributes(),
            ));
        });

        static::updated(function (self $model) {
            $changes = $model->activityChanges($model->getPrevious(), $model->getChanges());

            if ($changes) {
                $model->logActivity($model->activityAction($changes), $changes);
            }
        });
    }

    public function activityLabel(): string
    {
        return class_basename($this).' #'.$this->getKey();
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    public function activityAction(array $changes): string
    {
        return 'updated';
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>|null  $changes
     */
    protected function logActivity(string $action, ?array $changes): void
    {
        $log = new ActivityLog;
        $log->agency_id = $this->agency_id;
        $log->user_id = Auth::id();
        $log->action = $action;
        $log->subject()->associate($this);
        $log->subject_label = $this->activityLabel();
        $log->diff = $changes ?: null;
        $log->save();
    }

    /**
     * Build { field: { old, new } } for the fields that really changed.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array<string, array{old: mixed, new: mixed}>
     */
    protected function activityChanges(array $old, array $new): array
    {
        $ignored = array_merge(
            ['id', 'agency_id', 'created_at', 'updated_at'],
            $this->getHidden(),
            $this->activityIgnored ?? [],
        );
        $masked = $this->activityMasked ?? [];

        $changes = [];

        foreach ($new as $field => $value) {
            $oldValue = $this->activityValue($field, $old[$field] ?? null);
            $newValue = $this->activityValue($field, $value);

            if (in_array($field, $ignored, true) || $oldValue === $newValue) {
                continue;
            }

            $changes[$field] = in_array($field, $masked, true)
                ? ['old' => '(hidden)', 'new' => '(hidden)']
                : ['old' => $oldValue, 'new' => $newValue];
        }

        return $changes;
    }

    /**
     * Same format as the API: "270.00", "maintenance", "2026-10-21".
     */
    protected function activityValue(string $field, mixed $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        $value = $this->hasCast($field) ? $this->castAttribute($field, $raw) : $raw;

        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $this->getCasts()[$field] === 'date'
                ? $value->format('Y-m-d')
                : $this->serializeDate($value),
            default => $value,
        };
    }
}
