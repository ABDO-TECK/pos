<?php

namespace Tests\Unit;

use App\Services\ReleaseVersionPolicy;
use PHPUnit\Framework\TestCase;

class ReleaseVersionPolicyTest extends TestCase
{
    public function testV0ClientRejectsReleaseAtOrAboveOne(): void
    {
        $assessment = ReleaseVersionPolicy::assess('0.0.1', '1.2.0');

        $this->assertFalse($assessment['compatible']);
        $this->assertSame('legacy_generation', $assessment['reason_code']);
        $this->assertSame(ReleaseVersionPolicy::LEGACY_GENERATION_REASON, $assessment['reason']);
    }

    public function testV0ClientAcceptsNewerV0Release(): void
    {
        $assessment = ReleaseVersionPolicy::assess('0.0.1', '0.0.2');

        $this->assertTrue($assessment['compatible']);
        $this->assertNull($assessment['reason_code']);
        $this->assertNull($assessment['reason']);
    }

    public function testInvalidVersionMetadataIsRejected(): void
    {
        $assessment = ReleaseVersionPolicy::assess('0.0.1', 'not-semver');

        $this->assertFalse($assessment['compatible']);
        $this->assertSame('invalid_version', $assessment['reason_code']);
        $this->assertSame(ReleaseVersionPolicy::INVALID_VERSION_REASON, $assessment['reason']);
    }
}
