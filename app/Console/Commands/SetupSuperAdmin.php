<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Setup\SuperAdminSetup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * First-run setup from the terminal: create the super admin here, or hand out a key for the /setup page.
 * Without a terminal (e.g. CI or `--no-interaction`) it prints the web setup key instead of prompting.
 */
#[Signature('ipile:setup')]
#[Description('Create the first super admin, in the terminal or through the /setup page')]
class SetupSuperAdmin extends Command
{
    public function handle(SuperAdminSetup $setup): int
    {
        if (! $setup->isRequired()) {
            $this->components->info('Setup is already complete: a super admin exists. Use `php artisan ipile:make-super-admin {email}` to add another.');

            return self::SUCCESS;
        }

        if (! $this->input->isInteractive()) {
            return $this->printWebSetupKey($setup);
        }

        $method = select('How do you want to create the super admin?', [
            'terminal' => 'Here in the terminal',
            'web' => 'In the browser (prints a one-time setup key)',
        ]);

        return $method === 'web' ? $this->printWebSetupKey($setup) : $this->createInTerminal($setup);
    }

    protected function createInTerminal(SuperAdminSetup $setup): int
    {
        $email = text('Email address', required: true, validate: fn (string $value) => $this->validationError('email', $value, ['email', 'max:255']));
        $existing = User::query()->where('email', strtolower($email))->first();

        if ($existing !== null) {
            if ($existing->is_guest || ! confirm("{$existing->email} already has an account. Make it the super admin?")) {
                $this->components->error('No changes made.');

                return self::FAILURE;
            }

            $setup->promote($existing);
            $this->components->info("{$existing->email} is now the super admin.");

            return self::SUCCESS;
        }

        $name = text('Name', required: true, validate: fn (string $value) => $this->validationError('name', $value, ['max:255']));
        $password = password('Password', required: true, validate: fn (string $value) => $this->validationError('password', $value, [Password::defaults()]));
        password('Confirm password', required: true, validate: fn (string $value) => $value === $password ? null : 'The passwords do not match.');

        $user = $setup->createSuperAdmin(['name' => $name, 'email' => strtolower($email), 'password' => $password]);
        $this->components->info("{$user->email} is now the super admin. Sign in at ".route('login'));

        return self::SUCCESS;
    }

    protected function printWebSetupKey(SuperAdminSetup $setup): int
    {
        $this->components->info('Open '.route('setup.create').' and enter this one-time setup key:');
        $this->line('  '.$setup->issueKey());
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @param  array<int, mixed>  $rules
     */
    protected function validationError(string $attribute, string $value, array $rules): ?string
    {
        return Validator::make([$attribute => $value], [$attribute => $rules])->errors()->first($attribute) ?: null;
    }
}
