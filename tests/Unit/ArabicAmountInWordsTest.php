<?php

namespace Tests\Unit;

use App\Services\Papers\ArabicAmountInWords;
use PHPUnit\Framework\TestCase;

/** M4.20: amounts in words for the legal papers. */
class ArabicAmountInWordsTest extends TestCase
{
    public function test_common_amounts(): void
    {
        $this->assertSame('صفر', ArabicAmountInWords::convert(0));
        $this->assertSame('واحد', ArabicAmountInWords::convert(1));
        $this->assertSame('أحد عشر', ArabicAmountInWords::convert(11));
        $this->assertSame('خمسة وعشرون', ArabicAmountInWords::convert(25));
        $this->assertSame('مئة', ArabicAmountInWords::convert(100));
        $this->assertSame('مئتان وثلاثة وأربعون', ArabicAmountInWords::convert(243));
    }

    public function test_thousands_and_millions_follow_the_counting_rules(): void
    {
        $this->assertSame('ألف', ArabicAmountInWords::convert(1000));
        $this->assertSame('ألفان', ArabicAmountInWords::convert(2000));
        $this->assertSame('ثلاثة آلاف', ArabicAmountInWords::convert(3000));
        $this->assertSame('أحد عشر ألفاً', ArabicAmountInWords::convert(11000));
        $this->assertSame('مئة ألف', ArabicAmountInWords::convert(100000));
        $this->assertSame('مئتا ألف', ArabicAmountInWords::convert(200000));
        $this->assertSame('ثلاثمئة ألف', ArabicAmountInWords::convert(300000));
        $this->assertSame('مئة وثلاثة آلاف', ArabicAmountInWords::convert(103000));
        $this->assertSame('مئة وأحد عشر ألفاً', ArabicAmountInWords::convert(111000));
        $this->assertSame('مئتا مليون', ArabicAmountInWords::convert(200000000));
        $this->assertSame('مليون وخمسمئة ألف', ArabicAmountInWords::convert(1500000));
        $this->assertSame('مليونان وستة وخمسون ألفاً', ArabicAmountInWords::convert(2056000));
        $this->assertSame('مئتان وستة وخمسون مليوناً', ArabicAmountInWords::convert('256.000.000'));
        $this->assertSame('ستة وخمسون مليوناً', ArabicAmountInWords::convert(56000000));
        $this->assertSame('ثمانية وخمسون مليوناً وخمسمئة ألف', ArabicAmountInWords::convert(58500000));
        $this->assertSame('ملياران وثلاثة ملايين', ArabicAmountInWords::convert(2003000000));
    }
}
