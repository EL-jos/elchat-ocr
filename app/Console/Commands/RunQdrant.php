<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RunQdrant extends Command
{
    protected $signature = 'qdrant:serve';
    protected $description = 'Lancer Qdrant localement';

    public function handle()
    {
        $exe = public_path('tools/qdrant/qdrant.exe');
        $config = public_path('tools/qdrant/config.yaml');
        $cwd = public_path('tools/qdrant');

        // Lancer Qdrant détaché avec le bon working directory
        $command = 'cmd /c start "" /D "' . $cwd . '" "' . $exe . '" --config-path "' . $config . '"';

        pclose(popen($command, 'r'));

        $this->info('✅ Qdrant lancé en arrière-plan');
        $this->info('🌐 http://127.0.0.1:6333');
    }
}
