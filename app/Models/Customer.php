<?php

namespace App\Models;

use App\Enums\CustomerType;
use App\Enums\IdType;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\NumberedPerAgency;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'first_name',
    'last_name',
    'phone',
    'email',
    'address',
    'city',
    'id_type',
    'id_number',
    'nationality',
    'birth_date',
    'licence_number',
    'licence_issue_date',
    'notes',
])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use BelongsToAgency, HasFactory, LogsActivity, NumberedPerAgency;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'individual',
    ];

    /**
     * Footprints: id_key always follows id_number; ID and licence numbers are personal data.
     *
     * @var list<string>
     */
    protected array $activityIgnored = ['id_key'];

    /**
     * @var list<string>
     */
    protected array $activityMasked = ['id_number', 'licence_number'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_number' => 'integer',
            'type' => CustomerType::class,
            'id_type' => IdType::class,
            'birth_date' => 'date',
            'licence_issue_date' => 'date',
            'blocked_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function numberColumn(): string
    {
        return 'customer_number';
    }

    public function numberPrefix(): string
    {
        return 'C-';
    }

    /**
     * Comparison key: letters and digits only. "ab 123 456" → "AB123456".
     */
    public static function idKey(?string $idNumber): string
    {
        return preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtoupper((string) $idNumber));
    }

    /**
     * Phone stored as "+" and digits only, so searching "0600 000" finds it.
     */
    public static function normalizePhone(?string $phone): string
    {
        $phone = trim((string) $phone);

        return (str_starts_with($phone, '+') ? '+' : '').preg_replace('/\D/', '', $phone);
    }

    /**
     * Setting the ID number also sets its comparison key.
     *
     * @return Attribute<string, string>
     */
    protected function idNumber(): Attribute
    {
        return Attribute::set(fn (?string $value) => [
            'id_number' => mb_strtoupper(trim((string) $value)),
            'id_key' => static::idKey($value),
        ]);
    }

    /**
     * @return Attribute<string, string>
     */
    protected function phone(): Attribute
    {
        return Attribute::set(fn (?string $value) => static::normalizePhone($value));
    }

    /**
     * Customer code shown to people, e.g. C-012.
     *
     * @return Attribute<string, never>
     */
    protected function customerCode(): Attribute
    {
        return Attribute::get(fn () => $this->code());
    }

    /**
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => $this->first_name.' '.$this->last_name);
    }

    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    #[Scope]
    protected function archived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }

    public function activityLabel(): string
    {
        return $this->code().' '.$this->full_name;
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    public function activityAction(array $changes): string
    {
        return match (true) {
            array_key_exists('blocked_at', $changes) => $changes['blocked_at']['new'] === null ? 'unblocked' : 'blocked',
            array_key_exists('archived_at', $changes) => $changes['archived_at']['new'] === null ? 'restored' : 'archived',
            default => 'updated',
        };
    }
}
