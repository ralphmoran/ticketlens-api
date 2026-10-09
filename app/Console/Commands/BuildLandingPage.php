<?php

namespace App\Console\Commands;

use App\Services\LandingPageBuilder;
use Illuminate\Console\Command;

class BuildLandingPage extends Command
{
    protected $signature   = 'landing:build {--out= : Output path (default: public/landing.html)}';
    protected $description = 'Render resources/landing/index.html with prices from config/tiers.php';

    public function handle(LandingPageBuilder $builder): int
    {
        if (app()->isProduction() && str_ends_with((string) config('tiers.enterprise_contact_email'), '.test')) {
            $this->error('ENTERPRISE_CONTACT_EMAIL is still a .test address; set a real one before building in production.');

            return self::FAILURE;
        }

        $out  = $this->option('out') ?: public_path('landing.html');
        $html = $builder->renderFromConfig(file_get_contents(resource_path('landing/index.html')));

        file_put_contents($out, $html);
        $this->info("Landing page written to {$out}");

        return self::SUCCESS;
    }
}
