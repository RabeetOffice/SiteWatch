<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Permission;
use PHPUnit\Framework\TestCase;

final class PermissionTest extends TestCase
{
    public function testDecodeKeepsKnownKeysInCatalogueOrder(): void
    {
        self::assertSame(['websites.manage', 'domains.view'], Permission::decode('["domains.view","made.up","websites.manage","domains.view"]'));
        self::assertSame([Permission::ALL], Permission::decode('["*","websites.manage"]'));
        self::assertSame([], Permission::decode('not json'));
        self::assertSame([], Permission::decode(null));
    }

    public function testAllows(): void
    {
        self::assertTrue(Permission::allows([Permission::ALL], 'users.manage'));
        self::assertTrue(Permission::allows(['users.manage'], 'users.manage'));
        self::assertFalse(Permission::allows(['users.manage'], 'roles.manage'));
        self::assertFalse(Permission::allows([], 'domains.view'));
    }

    public function testExpandWildcard(): void
    {
        self::assertSame(Permission::keys(), Permission::expand([Permission::ALL]));
        self::assertSame(['reports.view'], Permission::expand(['reports.view', 'unknown']));
    }

    public function testCoversPreventsEscalation(): void
    {
        $manager = ['websites.manage', 'domains.view', 'users.manage'];

        self::assertTrue(Permission::covers([Permission::ALL], $manager), 'administrators can manage anyone');
        self::assertTrue(Permission::covers($manager, ['domains.view']), 'a subset is fine');
        self::assertTrue(Permission::covers($manager, $manager), 'equal access is fine');
        self::assertTrue(Permission::covers($manager, []), 'no permissions is fine');
        self::assertFalse(Permission::covers($manager, ['domains.view', 'settings.manage']), 'granting a permission you lack');
        self::assertFalse(Permission::covers($manager, [Permission::ALL]), 'only administrators can manage administrators');
    }

    public function testEveryKeyHasALabel(): void
    {
        foreach (Permission::keys() as $key) {
            self::assertNotSame($key, Permission::label($key));
            self::assertTrue(Permission::exists($key));
        }
        self::assertFalse(Permission::exists('*'));
    }
}
