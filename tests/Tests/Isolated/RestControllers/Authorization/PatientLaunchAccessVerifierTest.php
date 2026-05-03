<?php

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\RestControllers\Authorization;

use OpenEMR\RestControllers\Authorization\PatientLaunchAccessVerifier;
use OpenEMR\Services\UserService;
use PHPUnit\Framework\TestCase;

class PatientLaunchAccessVerifierTest extends TestCase
{
    /** @return callable(string): bool */
    private static function noopPatientLookup(): callable
    {
        return static fn (string $_uuid): bool => false;
    }

    public function testEmptyOAuthUserUuidDenied(): void
    {
        $verifier = new PatientLaunchAccessVerifier(
            $this->createMock(UserService::class),
            self::noopPatientLookup()
        );
        $this->assertFalse($verifier->userMayBindSmartPatient('', '550e8400-e29b-41d4-a716-446655440000'));
    }

    public function testEmptyPatientUuidDenied(): void
    {
        $verifier = new PatientLaunchAccessVerifier(
            $this->createMock(UserService::class),
            self::noopPatientLookup()
        );
        $this->assertFalse($verifier->userMayBindSmartPatient('550e8400-e29b-41d4-a716-446655440000', ''));
    }

    public function testUnknownUserDenied(): void
    {
        $userService = $this->createMock(UserService::class);
        $userService->method('getUserByUUID')->willReturn([]);
        $verifier = new PatientLaunchAccessVerifier($userService, self::noopPatientLookup());
        $this->assertFalse($verifier->userMayBindSmartPatient('550e8400-e29b-41d4-a716-446655440000', '660e8400-e29b-41d4-a716-446655440000'));
    }

    public function testUserMissingUsernameDenied(): void
    {
        $userService = $this->createMock(UserService::class);
        $userService->method('getUserByUUID')->willReturn(['id' => 1]);
        $verifier = new PatientLaunchAccessVerifier($userService, self::noopPatientLookup());
        $this->assertFalse($verifier->userMayBindSmartPatient('550e8400-e29b-41d4-a716-446655440000', '660e8400-e29b-41d4-a716-446655440000'));
    }
}
