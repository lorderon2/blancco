<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdministrator extends Command
{
    protected $signature = 'blancco:admin {email} {--name=Administrator}';

    protected $description = 'Tworzy administratora, pytając o hasło bez zapisywania go w historii poleceń';

    public function handle(): int
    {
        $data = ['email' => $this->argument('email'), 'name' => $this->option('name'), 'password' => $this->secret('Hasło (co najmniej 12 znaków)')];
        $validator = Validator::make($data, ['email' => 'required|email|unique:users', 'name' => 'required|string|max:255', 'password' => ['required', Password::min(12)]]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        User::create($data + ['role' => 'admin']);
        $this->info('Utworzono administratora.');

        return self::SUCCESS;
    }
}
