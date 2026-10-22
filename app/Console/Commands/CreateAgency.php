<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\User;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

/**
 * Create a client agency and its owner account (until the super admin dashboard exists).
 * The owner gets a temporary password (shown once) and must change it at first login,
 * then completes the agency's details in Settings.
 */
class CreateAgency extends Command
{
    /**
     * @var string
     */
    protected $signature = 'agency:create
        {--name= : Agency name}
        {--city= : Agency city}
        {--owner-name= : Owner full name}
        {--owner-email= : Owner email (login)}
        {--currency=MAD : Set once, never changed later}
        {--timezone=Africa/Casablanca : Set once, never changed later}';

    /**
     * @var string
     */
    protected $description = 'Create a client agency and its owner account (temporary password)';

    public function handle(): int
    {
        $data = [
            'name' => $this->option('name') ?: text('Agency name', required: true),
            'city' => $this->option('city') ?: text('City', required: true),
            'owner_name' => $this->option('owner-name') ?: text('Owner full name', required: true),
            'owner_email' => $this->option('owner-email') ?: text('Owner email (login)', required: true),
            'currency' => strtoupper((string) $this->option('currency')),
            'timezone' => (string) $this->option('timezone'),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:50'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'timezone' => ['required', 'in:'.implode(',', DateTimeZone::listIdentifiers())],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $password = Str::password(12, symbols: false);

        [$agency, $owner] = DB::transaction(function () use ($data, $password) {
            $agency = Agency::create([
                'name' => $data['name'],
                'city' => $data['city'],
                'currency' => $data['currency'],
                'timezone' => $data['timezone'],
            ]);

            $owner = new User([
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => $password,
            ]);
            $owner->agency_id = $agency->id;
            $owner->role = Role::Owner;
            $owner->must_change_password = true;
            $owner->save();

            return [$agency, $owner];
        });

        $this->info("Agency \"{$agency->name}\" created (id {$agency->id}, {$agency->currency}, {$agency->timezone}).");
        $this->table(['Owner', 'Login', 'Temporary password'], [[$owner->name, $owner->email, $password]]);
        $this->warn('Give this password to the owner privately. It is shown only now; they must change it at first login.');

        return self::SUCCESS;
    }
}
