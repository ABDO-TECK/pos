<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Security\QzRequestPolicy;
use PHPUnit\Framework\TestCase;

final class QzRequestPolicyTest extends TestCase
{
    public function testNormalizesAValidSha256Digest(): void
    {
        $digest = str_repeat('AB', 32);

        $this->assertSame(strtolower($digest), QzRequestPolicy::normalizeDigest("  {$digest}  "));
    }

    public function testRejectsArbitraryRequestData(): void
    {
        $this->assertNull(QzRequestPolicy::normalizeDigest('print raw command'));
        $this->assertNull(QzRequestPolicy::normalizeDigest(str_repeat('a', 63)));
        $this->assertNull(QzRequestPolicy::normalizeDigest(str_repeat('g', 64)));
    }
}
