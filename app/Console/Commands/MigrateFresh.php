<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateFresh extends Command
{
    protected $signature = 'migrate:fresh-production';
    protected $description = 'Run fresh migrations on production';

    public function handle()
    {
        // Create database if not exists
        $path = env('DB_DATABASE', '/tmp/database.sqlite');
        $dir = dirname($path);
        
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        
        if (!file_exists($path)) {
            file_put_contents($path, '');
            $this->info('Database created at: ' . $path);
        }
        
        // Run migrations
        $this->call('migrate', ['--force' => true]);
        
        // Seed
        $this->call('db:seed', [
            '--class' => 'LandmarkSeeder',
            '--force' => true
        ]);
        
        $this->info('Migration completed successfully!');
    }
}