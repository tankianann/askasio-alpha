<?php

declare(strict_types=1);

use App\Auth\AuthenticationService;
use App\Auth\PasswordHasher;
use App\Database\Connection;
use App\Repositories\PdoAdminRepository;
use App\Support\Config;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();

/** @return string */
function prompt(string $label): string
{
    fwrite(STDOUT, $label);
    $value = fgets(STDIN);

    return $value === false ? '' : trim($value);
}

/** @return string */
function promptHidden(string $label): string
{
    fwrite(STDOUT, $label);
    $interactive = function_exists('stream_isatty') && stream_isatty(STDIN);
    $canHideInput = $interactive && PHP_OS_FAMILY !== 'Windows' && function_exists('shell_exec');

    if ($canHideInput) {
        shell_exec('stty -echo');
    }

    try {
        $value = fgets(STDIN);
    } finally {
        if ($canHideInput) {
            shell_exec('stty echo');
            fwrite(STDOUT, PHP_EOL);
        }
    }

    return $value === false ? '' : rtrim($value, "\r\n");
}

try {
    $config = Config::load($root . '/config');
    $admins = new PdoAdminRepository(new Connection($config));

    if ($admins->count() > 0) {
        throw new RuntimeException('An administrator already exists. This single-user application permits one administrator.');
    }

    $username = AuthenticationService::normalizeUsername(prompt('Username: '));

    if (preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $username) !== 1) {
        throw new RuntimeException('Username must be 3-64 characters and use only lowercase letters, numbers, dots, underscores, or hyphens.');
    }

    $password = promptHidden('Password: ');
    $confirmation = promptHidden('Confirm password: ');

    if (!hash_equals($password, $confirmation)) {
        throw new RuntimeException('Password confirmation does not match.');
    }

    if (strlen($password) < 10) {
        throw new RuntimeException('Password must contain at least 10 characters.');
    }

    if (strlen($password) > 4096) {
        throw new RuntimeException('Password is too long.');
    }

    $admin = $admins->create($username, (new PasswordHasher())->hash($password));
    fwrite(STDOUT, sprintf("Administrator '%s' created with ID %d.\n", $admin->username, $admin->id));
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("Administrator creation failed: %s\n", $exception->getMessage()));
    exit(1);
}
