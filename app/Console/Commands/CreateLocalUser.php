<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\UserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class CreateLocalUser extends Command
{
    protected $signature = 'user:create
        {--email= : Login email address}
        {--password= : Login password, 8 to 32 characters}
        {--name= : Display name, defaults to email}
        {--study-language=english : Default study language}
        {--admin : Create the user as an admin}';

    protected $description = 'Create a local LinguaCafe user for development.';

    public function handle(UserService $userService): int
    {
        $email = trim((string) $this->option('email'));
        $password = (string) $this->option('password');
        $name = trim((string) ($this->option('name') ?: $email));
        $studyLanguage = strtolower(trim((string) $this->option('study-language'))) ?: 'english';
        $isAdmin = (bool) $this->option('admin') || User::count() === 0;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid --email option is required.');

            return self::FAILURE;
        }

        if (strlen($password) < 8 || strlen($password) > 32) {
            $this->error('The --password option must be 8 to 32 characters.');

            return self::FAILURE;
        }

        if ($name === '') {
            $this->error('The --name option cannot be empty.');

            return self::FAILURE;
        }

        try {
            $userService->createUser($name, $email, $password, $isAdmin, true, $studyLanguage);
            $user = User::where('email', $email)->firstOrFail();
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (!$user || !Hash::check($password, $user->password) || !Auth::validate(['email' => $email, 'password' => $password])) {
            $this->error('User was created, but the login credentials could not be verified.');

            return self::FAILURE;
        }

        $this->info('Local user created successfully.');
        $this->line("Email: {$email}");
        $this->line("Password: {$password}");

        return self::SUCCESS;
    }
}
