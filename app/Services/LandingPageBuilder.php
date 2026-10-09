<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Renders the public landing page template, injecting plan prices so the
 * marketing page can never drift from config/tiers.php.
 */
class LandingPageBuilder
{
    private const PLANS = ['pro', 'team'];

    /**
     * @param array<string,int|float> $prices          tier => monthly price (config('tiers.prices'))
     * @param int|float               $discountPercent annual billing discount
     * @param array<string,string>    $extras          extra placeholders; values are HTML-escaped
     */
    public function render(string $template, array $prices, int|float $discountPercent, array $extras = []): string
    {
        $values = $this->values($prices, $discountPercent) + array_map(
            fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES),
            $extras,
        );

        return preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/',
            fn (array $m) => $values[$m[1]]
                ?? throw new InvalidArgumentException("Unknown landing placeholder: {$m[1]}"),
            $template,
        );
    }

    /** Render using config/tiers.php: prices, annual discount and enterprise contact. */
    public function renderFromConfig(string $template): string
    {
        $email = (string) $this->config('tiers.enterprise_contact_email');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid tiers.enterprise_contact_email: {$email}");
        }

        return $this->render(
            $template,
            $this->config('tiers.prices'),
            $this->config('tiers.annual_discount_percent'),
            ['enterprise_email' => $email],
        );
    }

    protected function config(string $key): mixed
    {
        return config($key);
    }

    /** @return array<string,string> */
    private function values(array $prices, int|float $discountPercent): array
    {
        $values = ['annual_discount' => $this->format($discountPercent)];

        foreach (self::PLANS as $plan) {
            $monthly  = $prices[$plan];
            $perMonth = $monthly * (1 - $discountPercent / 100);

            $values["{$plan}_monthly"]          = $this->format($monthly);
            $values["{$plan}_annual_per_month"] = $this->format($perMonth);
            $values["{$plan}_annual_total"]     = $this->format(round($perMonth * 12));
        }

        return $values;
    }

    private function format(int|float $amount): string
    {
        $amount = round($amount, 2);

        return $amount == (int) $amount
            ? (string) (int) $amount
            : number_format($amount, 2, '.', '');
    }
}
