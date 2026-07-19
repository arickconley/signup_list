<?php

namespace App\Console\Commands;

use App\Support\ProductionConfiguration;
use Illuminate\Console\Command;

final class ProductionCheck extends Command
{
    protected $signature = 'app:production-check';

    protected $description = 'Validate production deployment configuration without changing state';

    public function handle(ProductionConfiguration $configuration): int
    {
        $errors = $configuration->errors();

        if ($errors !== []) {
            $this->components->error('Production configuration is not ready.');

            foreach ($errors as $error) {
                $this->line("  - {$error}");
            }

            return self::FAILURE;
        }

        $this->components->info('Production configuration is ready.');

        return self::SUCCESS;
    }
}
