<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProfileService;
use UserRepository;

final class ProfileServiceTest extends TestCase
{
    public function testUpdateProfileFailsWhenDisplayNameExceeds120Chars(): void
    {
        $userRepository = $this->createStub(UserRepository::class);

        $service = new ProfileService($userRepository, null);

        $longName = str_repeat('a', 121); // 121 ตัวอักษร > 120

        $result = $service->updateProfile(1, $longName);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('120', $result['error']);
    }
}
