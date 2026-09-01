<?php

namespace Tests\Unit;

use App\Services\MoneyService;
use InvalidArgumentException;
use Tests\TestCase;

class MoneyServiceTest extends TestCase
{
    public function test_it_converts_currency_to_minor_units_without_float_logic_in_callers(): void
    {
        $service = new MoneyService();

        $this->assertSame(1000, $service->toMinorUnits('10.00', 'GHS'));
        $this->assertSame(1000, $service->toMinorUnits('1000', 'JPY'));
    }

    public function test_it_rejects_non_positive_amounts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MoneyService())->toMinorUnits('0.00', 'GHS');
    }
}
