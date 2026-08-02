<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use UserRepository;

final class UserRepositoryTest extends IntegrationTestCase
{
    public function testIncrementSessionVersionPersistsToDatabase(): void
    {
        $userId = $this->createUser();
        $repo = new UserRepository($this->pdo);

        // default session_version = 1 (ตาม schema)
        $this->assertSame(1, $repo->getSessionVersion($userId));

        $repo->incrementSessionVersion($userId);
        $this->assertSame(2, $repo->getSessionVersion($userId));

        $repo->incrementSessionVersion($userId);
        $this->assertSame(3, $repo->getSessionVersion($userId));
    }
}
