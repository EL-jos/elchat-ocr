<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RunMeilisearchServer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'meilisearch:serve';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Lance le serveur Meilisearch';

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
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Lancement du serveur Meilisearch...');

        $meilisearchKey = env('MEILISEARCH_KEY');

        $command = "cd public/tools/meilisearch; " .
            "./meilisearch.exe --master-key=$meilisearchKey";

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

        $this->info('Le serveur Meilisearch a été lancé avec succès.');
    }
}
