<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;

class ClearLogsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'logs:clear';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear Laravel log files';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Récupère tous les fichiers .log dans le dossier storage/logs
        $files = File::glob(storage_path('logs/*.log'));

        foreach ($files as $file) {
            // Option 1 : Supprimer carrément le fichier
            File::delete($file);

            // Option 2 (Préférée) : Vider le contenu sans supprimer le fichier
            // (évite les erreurs de permission si Laravel écrit dedans au même moment)
            // File::put($file, '');
        }

        $this->info('Logs have been cleared successfully on Windows!');
    }
}
