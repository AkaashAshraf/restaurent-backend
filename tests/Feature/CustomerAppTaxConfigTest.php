<?php

namespace Tests\Feature;

use Tests\TestCase;

/** The customer app shows tax for cash as well as for online payments. */
class CustomerAppTaxConfigTest extends TestCase
{
    public function test_app_config_exposes_the_cash_and_online_tax_rates(): void
    {
        [$restaurant] = $this->makeRestaurantWithOwner('Tax Co');
        $restaurant->settings->update(['tax_enabled' => true, 'tax_percentage' => 10, 'cash_tax_percentage' => 5, 'card_tax_percentage' => 15]);

        $this->getJson('/api/v1/app/config?restaurant='.$restaurant->slug)->assertOk()
            ->assertJsonPath('data.ordering.tax_enabled', true)
            ->assertJsonPath('data.ordering.cash_tax_percentage', 5)
            ->assertJsonPath('data.ordering.online_tax_percentage', 15);
    }

    public function test_the_cash_rate_falls_back_to_the_default_and_is_zero_when_tax_is_off(): void
    {
        [$restaurant] = $this->makeRestaurantWithOwner('Tax Two');
        $restaurant->settings->update(['tax_enabled' => true, 'tax_percentage' => 10, 'cash_tax_percentage' => null]);
        $this->getJson('/api/v1/app/config?restaurant='.$restaurant->slug)->assertJsonPath('data.ordering.cash_tax_percentage', 10);

        $restaurant->settings->update(['tax_enabled' => false]);
        $this->getJson('/api/v1/app/config?restaurant='.$restaurant->slug)->assertJsonPath('data.ordering.cash_tax_percentage', 0);
    }
}
