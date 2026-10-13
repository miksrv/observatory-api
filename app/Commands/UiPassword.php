<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Hash a password for the web UI's `ui.password` in .env, so the file never holds it in plain text.
 *
 * Usage: php spark ui:password   (asks for the password without echoing it)
 */
class UiPassword extends BaseCommand
{
    protected $group       = 'Web UI';
    protected $name        = 'ui:password';
    protected $description = 'Hash a password for ui.password in .env';
    protected $usage       = 'ui:password';

    public function run(array $params): void
    {
        $password = $this->readHidden('Password: ');
        $repeat   = $this->readHidden('Repeat:   ');

        if ($password === '') {
            CLI::error('The password is empty.');

            return;
        }

        if ($password !== $repeat) {
            CLI::error('The passwords do not match.');

            return;
        }

        if (strlen($password) < 10) {
            CLI::write('Warning: shorter than 10 characters.', 'yellow');
        }

        CLI::newLine();
        CLI::write('Put this line into .env (the single quotes matter):');
        CLI::write("ui.password = '" . password_hash($password, PASSWORD_DEFAULT) . "'", 'green');
    }

    /** One line from the terminal without echoing it (echoed when stdin isn't a terminal). */
    private function readHidden(string $prompt): string
    {
        CLI::print($prompt);

        $tty = stream_isatty(STDIN);
        if ($tty) {
            shell_exec('stty -echo');
        }

        $line = fgets(STDIN);

        if ($tty) {
            shell_exec('stty echo');
            CLI::newLine();
        }

        return rtrim((string) $line, "\r\n");
    }
}
