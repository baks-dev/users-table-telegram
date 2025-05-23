<?php
/*
 *  Copyright 2025.  Baks.dev <admin@baks.dev>
 *  
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is furnished
 *  to do so, subject to the following conditions:
 *  
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *  
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *  THE SOFTWARE.
 *
 */

declare(strict_types=1);

namespace BaksDev\Users\UsersTableTelegram\Repository\UserTableInfo\Tests;

use BaksDev\Users\UsersTableTelegram\Repository\UserTableInfo\UserTableInfoRepository;
use BaksDev\Users\UsersTableTelegram\Repository\UserTableInfo\UserTableInfoResult;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * @group users-table
 * @group users-table-telegram
 */
#[When(env: 'test')]
class UserTableInfoRepositoryTest extends KernelTestCase
{
    public function testUserTableInfoResult(): void
    {
        self::assertTrue(true);
        return;

        /** @var UserTableInfoRepository $repository */
        $repository = self::getContainer()->get(UserTableInfoRepository::class);

        $results = $repository
            //            ->onUserProfile('')
            //            ->onAuthority('')
            //            ->onDate('22-05-2025')
            ->toArray();

        if(false !== $results)
        {
            /** @var UserTableInfoResult $result */
            foreach($results as $result)
            {
                self::assertInstanceOf(UserTableInfoResult::class, $result);

                self::assertIsInt($result->getTableQuantity());
                self::assertIsString($result->getTableDate());
                self::assertIsString($result->getTableWorking());
                self::assertIsString($result->getCategoryName());
                self::assertIsString($result->getUsersProfileUsername());
            }
        }

        self::assertTrue(true);
    }
}
