<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

class SeedIfEmpty extends Command
{
    protected $signature = 'app:seed-if-empty';

    protected $description = 'Seed the database only when the product catalog is empty.';

    public function handle(): int
    {
        // withTrashed: a soft-deleted catalog still means the seeder has run.
        if (Product::withTrashed()->exists()) {
            $this->info('Catalog already populated; skipping seed.');

            return self::SUCCESS;
        }

        $this->info('Empty catalog; seeding.');
        $this->call('db:seed', ['--force' => true]);

        return self::SUCCESS;
    }
}
