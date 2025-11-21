<?php
declare(strict_types=1);

use Cashu\WC\Helpers\CashuHelper;
use PHPUnit\Framework\TestCase;

class FiatConversionTest extends TestCase {
  private CashuHelper $helper;

  protected function setUp(): void {
    if (!function_exists('wp_remote_get')) {
      $this->markTestSkipped('wp_remote_get not available in this test environment.');
    }
    $this->helper = new CashuHelper();
  }

  public function test_fiat_to_sats_returns_integer(): void {
    $sats = $this->helper->fiatToSats(10.0, 'usd');
    $this->assertIsInt($sats);
    $this->assertGreaterThan(10000, $sats); // sanity check
  }

  public function test_fiat_to_sats_is_reasonable(): void {
    // As of Nov 2025, 1 USD ≈ 1000–2000 sats
    $sats = $this->helper->fiatToSats(1.0, 'usd');
    $this->assertGreaterThan(800, $sats);
    $this->assertLessThan(5000, $sats);
  }

  public function test_handles_different_currencies(): void {
    $satsUsd = $this->helper->fiatToSats(100.0, 'usd');
    $satsEur = $this->helper->fiatToSats(100.0, 'eur');
    // EUR usually slightly stronger than USD
    $this->assertNotEquals($satsUsd, $satsEur);
  }
}
