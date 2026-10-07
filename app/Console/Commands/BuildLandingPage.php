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
        $out  = $this->option('out') ?: public_path('landing.html');
        $html = $builder->render(
            file_get_contents(resource_path('landing/index.html')),
            config('tiers.prices'),
            config('tiers.annual_discount_percent'),
        );

        file_put_contents($out, $html);
        $this->info("Landing page written to {$out}");

        return self::SUCCESS;
    }
}
