<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {--email=admin@mkm.local} {--username=admin} {--name=MKM Administrator}';

    protected $description = 'Create the initial administrator and write temporary credentials to private local storage';

    public function handle(): int
    {
        if (User::where('role', 'ADMIN')->exists()) {
            $this->error('Administrator already exists. Existing accounts were not modified.');

            return self::FAILURE;
        }
        $password = Str::password(20);
        $user = User::create(['name' => $this->option('name'), 'username' => $this->option('username'), 'email' => $this->option('email'), 'password' => $password, 'role' => 'ADMIN', 'is_active' => true, 'must_change_password' => true]);
        Storage::disk('local')->put('bootstrap-admin.txt', "MKM Scrap Delivery - initial local administrator\nUsername: {$user->username}\nEmail: {$user->email}\nTemporary password: {$password}\nChange this password on first login. Remove this file after saving credentials securely.\n");
        $this->info('Administrator created. Temporary credentials: '.Storage::disk('local')->path('bootstrap-admin.txt'));

        return self::SUCCESS;
    }
}
