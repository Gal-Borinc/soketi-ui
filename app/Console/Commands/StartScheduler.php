<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StartScheduler extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'scheduler:start';

    /**
     * The console command description.
     */
    protected $description = 'Start the Laravel scheduler as a daemon process';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting Laravel scheduler...');
        $this->info('Press Ctrl+C to stop the scheduler');
        
        // Run the scheduler work command which keeps running
        return $this->call('schedule:work');
    }
}