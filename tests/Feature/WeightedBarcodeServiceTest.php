<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Setting;
use App\Services\WeightedBarcodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeightedBarcodeServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): WeightedBarcodeService
    {
        return app(WeightedBarcodeService::class);
    }

    public function test_generate_produces_13_digit_code_with_valid_check_digit()
    {
        $product = Product::factory()->create(['is_weighted' => true]);
        $code    = $this->service()->generate($product, 1.25);

        $this->assertSame(13, strlen($code));
        $this->assertMatchesRegularExpression('/^\d{13}$/', $code);
        $this->assertTrue($this->service()->validate($code));
    }

    public function test_parse_round_trips_product_id_and_weight()
    {
        $product = Product::factory()->create(['is_weighted' => true]);
        $code    = $this->service()->generate($product, 1.25);

        $parsed = $this->service()->parse($code);

        $this->assertNotNull($parsed);
        $this->assertSame($product->id, $parsed['product_id']);
        $this->assertEqualsWithDelta(1.25, $parsed['weight_kg'], 0.0001);
    }

    public function test_validate_rejects_wrong_check_digit()
    {
        $product = Product::factory()->create(['is_weighted' => true]);
        $code    = $this->service()->generate($product, 0.5);

        $lastDigit  = (int) substr($code, -1);
        $badDigit   = ($lastDigit + 1) % 10;
        $badCode    = substr($code, 0, -1) . $badDigit;

        $this->assertFalse($this->service()->validate($badCode));
        $this->assertNull($this->service()->parse($badCode));
    }

    public function test_validate_rejects_wrong_prefix()
    {
        // Обычный SKU выглядит как "20..." (13 цифр) — не должен приниматься
        // как весовой код (у которого префикс "21").
        $code = '2012345678903';

        $this->assertFalse($this->service()->isWeightedBarcode($code));
        $this->assertFalse($this->service()->validate($code));
    }

    public function test_validate_rejects_wrong_length()
    {
        $this->assertFalse($this->service()->validate('211234'));
        $this->assertFalse($this->service()->validate('211234567890123'));
    }

    public function test_generate_rejects_zero_weight()
    {
        $product = Product::factory()->create(['is_weighted' => true]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->generate($product, 0);
    }

    public function test_generate_rejects_negative_weight()
    {
        $product = Product::factory()->create(['is_weighted' => true]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->generate($product, -1);
    }

    public function test_generate_rejects_weight_over_format_limit()
    {
        $product = Product::factory()->create(['is_weighted' => true]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->generate($product, 999); // > 99.999кг при 5 цифрах грамм
    }

    public function test_generate_rejects_product_id_over_format_limit()
    {
        Setting::updateOrCreate(['key' => 'weighted_barcode_weight_digits'], ['value' => '5']);
        $product = Product::factory()->make(['is_weighted' => true]);
        $product->id = 100000; // > 99999, лимит 5-значного поля product_id
        $product->exists = true;

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->generate($product, 1.0);
    }
}
