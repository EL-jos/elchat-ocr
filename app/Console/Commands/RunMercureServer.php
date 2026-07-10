<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RunMercureServer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mercure:serve';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Lance le serveur Mercure';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @return void
     */
    public function handle()
    {
        $this->info('Lancement du serveur Mercure...');

        $command = 'cd public/tools/mercure; ' .
            '$env:JWT_KEY="!ChangeMe!"; ' .
            '$env:ADDR="localhost:3000"; ' .
            '$env:DEMO="0"; ' .
            '$env:ALLOW_ANONYMOUS="1"; ' .
            '$env:CORS_ALLOWED_ORIGINS="*"; ' .
            '$env:PUBLISH_ALLOWED_ORIGINS="http://localhost:8000"; ' .
            '.\mercure.exe';

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Exécution sous Windows via PowerShell
            $process = proc_open(
                ['powershell', '-Command', $command],
                [STDIN, STDOUT, STDERR],
                $pipes
            );
        } else {
            // Exécution sous Linux/Mac (bash)
            $process = proc_open(
                ['bash', '-c', $command],
                [STDIN, STDOUT, STDERR],
                $pipes
            );
        }

        if (is_resource($process)) {
            proc_close($process);
        }

        $this->info('Le serveur Mercure a été lancé avec succès.');
    }
}
