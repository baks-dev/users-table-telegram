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

namespace BaksDev\Users\UsersTableTelegram\Repository\UserTableInfo;

use BaksDev\Core\Doctrine\DBALQueryBuilder;
use BaksDev\Products\Category\Entity\CategoryProduct;
use BaksDev\Products\Category\Entity\Trans\CategoryProductTrans;
use BaksDev\Users\Profile\Group\Entity\Users\ProfileGroupUsers;
use BaksDev\Users\Profile\UserProfile\Entity\Event\Avatar\UserProfileAvatar;
use BaksDev\Users\Profile\UserProfile\Entity\Event\Info\UserProfileInfo;
use BaksDev\Users\Profile\UserProfile\Entity\Event\Personal\UserProfilePersonal;
use BaksDev\Users\Profile\UserProfile\Entity\Event\UserProfileEvent;
use BaksDev\Users\Profile\UserProfile\Entity\UserProfile;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Users\UsersTable\Entity\Actions\Event\UsersTableActionsEvent;
use BaksDev\Users\UsersTable\Entity\Actions\UsersTableActions;
use BaksDev\Users\UsersTable\Entity\Actions\Working\Trans\UsersTableActionsWorkingTrans;
use BaksDev\Users\UsersTable\Entity\Actions\Working\UsersTableActionsWorking;
use BaksDev\Users\UsersTable\Entity\Table\Event\UsersTableEvent;
use BaksDev\Users\UsersTable\Entity\Table\UsersTable;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Generator;
use InvalidArgumentException;

/** @see UserTableInfoResult */
final class UserTableInfoRepository
{
    private DateTimeImmutable|false $start = false;

    private DateTimeImmutable|false $end = false;

    private UserProfileUid|false $profile = false;

    private UserProfileUid|false $authority = false;

    public function __construct(
        private readonly DBALQueryBuilder $DBALQueryBuilder,
    ) {}

    /** Профиль пользователя */
    public function onUserProfile(UserProfile|UserProfileUid|string $profile): self
    {

        if(is_string($profile))
        {
            $profile = new UserProfileUid($profile);
        }

        if($profile instanceof UserProfile)
        {
            $profile = $profile->getId();
        }

        $this->profile = $profile;
        return $this;
    }

    /** Доверенности пользователя */
    public function onAuthority(UserProfile|UserProfileUid|string $authority): self
    {

        if(is_string($authority))
        {
            if(empty($authority))
            {
                throw new InvalidArgumentException('В параметр $authority передана пустая строка');
            }

            $authority = new UserProfileUid($authority);
        }

        if($authority instanceof UserProfile)
        {
            $authority = $authority->getId();
        }

        $this->authority = $authority;
        return $this;
    }

    /** Фильтр по дате */
    public function onDate(DateTimeImmutable|string $date): self
    {
        if($date instanceof DateTimeImmutable)
        {
            $this->start = $date->setTime(0, 0);
            $this->end = $date->setTime(23, 59, 59);
        }

        if(is_string($date))
        {
            $this->start = new DateTimeImmutable($date)
                ->setTime(0, 0);

            $this->end = new DateTimeImmutable($date)
                ->setTime(23, 59, 59);
        }

        return $this;
    }

    /** @return array<int, UserTableInfoResult>|false */
    public function toArray(): array|false
    {
        $result = $this->findAll();

        return (false !== $result) ? iterator_to_array($result) : false;
    }

    /** @return Generator<int, UserTableInfoResult>|false */
    public function findAll(): Generator|false
    {
        $dbal = $this->builder();
        $dbal->enableCache('users-table-telegram', '1 hour');

        $result = $dbal->fetchAllHydrate(UserTableInfoResult::class);

        return (true === $result->valid()) ? $result : false;
    }

    public function builder(): DBALQueryBuilder
    {

        if(false === ($this->profile instanceof UserProfileUid))
        {
            throw new InvalidArgumentException('Не передан обязательный параметр запроса $profile');
        }

        if(false === ($this->authority instanceof UserProfileUid))
        {
            throw new InvalidArgumentException('Не передан обязательный параметр запроса $authority');
        }

        $dbal = $this->DBALQueryBuilder
            ->createQueryBuilder(self::class)
            ->bindLocal();

        $dbal
            //            ->addSelect('users_table.id')
            //            ->addSelect('users_table.event')
            ->from(UsersTable::class, 'users_table');

        $dbal
            ->addSelect('SUM(event.quantity) AS table_quantity')
            ->addSelect('event.date_table AS table_date')
            ->leftJoin(
                'users_table',
                UsersTableEvent::class,
                'event',
                'event.id = users_table.event'
            );

        $dbal
            ->leftJoin(
                'event',
                ProfileGroupUsers::class,
                'profile_group_users',
                '
                profile_group_users.authority = :authority AND
                profile_group_users.profile = :profile'
            );

        /**
         * Действие сотрудника
         */
        $dbal
            ->leftJoin(
                'event',
                UsersTableActionsWorking::class,
                'working',
                'working.id = event.working'
            )
            ->groupBy('working.coefficient');

        $dbal
            ->addSelect('working_trans.name AS table_working')
            ->leftJoin(
                'working',
                UsersTableActionsWorkingTrans::class,
                'working_trans',
                'working_trans.working = working.id AND working_trans.local = :local'
            )
            ->groupBy('working_trans.name');

        $dbal->join(
            'action_event',
            UsersTableActions::class,
            'actions',
            'actions.id = action_event.main AND actions.profile = :authority'
        )
            ->setParameter('authority', $this->authority, UserProfileUid::TYPE);

        /**
         * Категория
         */
        $dbal
            ->leftJoin(
                'working',
                UsersTableActionsEvent::class,
                'action_event',
                'action_event.id = working.event'
            );

        $dbal
            ->leftJoin(
                'action_event',
                CategoryProduct::class,
                'category',
                'category.id = action_event.category'
            );

        $dbal
            ->addSelect('trans.name AS category_name')
            ->leftJoin(
                'category',
                CategoryProductTrans::class,
                'trans',
                'trans.event = category.event AND trans.local = :local'
            );

        /** Ответственное лицо */
        $dbal
            //            ->addSelect('users_profile.event as users_profile_event')
            ->join(
                'event',
                UserProfile::class,
                'users_profile',
                'users_profile.id = event.profile'
            );

        $dbal
            ->join(
                'event',
                UserProfileInfo::class,
                'users_profile_info',
                'users_profile_info.profile = event.profile'
            );

        $dbal
            ->join(
                'users_profile',
                UserProfileEvent::class,
                'users_profile_event',
                'users_profile_event.id = users_profile.event'
            );

        $dbal
            ->addSelect('users_profile_personal.username AS users_profile_username')
            ->join(
                'users_profile_event',
                UserProfilePersonal::class,
                'users_profile_personal',
                'users_profile_personal.event = users_profile_event.id'
            );

        $dbal
            ->leftJoin(
                'users_profile_event',
                UserProfileAvatar::class,
                'users_profile_avatar',
                'users_profile_avatar.event = users_profile_event.id'
            );

        /** Если дата не передана - показываем на текущую дату  */
        if(false === $this->start or false === $this->end)
        {
            $this->start = new DateTimeImmutable('now')
                ->setTime(0, 0);

            $this->end = new DateTimeImmutable('now')
                ->setTime(23, 59, 59);
        }

        $dbal
            ->andWhere('event.date_table BETWEEN :start AND :end')
            ->setParameter('start', $this->start, Types::DATETIME_IMMUTABLE)
            ->setParameter('end', $this->end, Types::DATETIME_IMMUTABLE);

        $dbal
            ->andWhere('event.profile = profile_group_users.profile')
            ->setParameter('authority', $this->authority, UserProfileUid::TYPE)
            ->setParameter('profile', $this->profile, UserProfileUid::TYPE);

        //        $dbal
        //            ->andWhere('event.profile = :profile')
        //            ->setParameter('profile', $this->profile, UserProfileUid::TYPE);

        $dbal->orderBy('SUM(working.coefficient)', 'ASC');
        //        $dbal->orderBy('SUM(working.sort)', 'ASC');

        $dbal->allGroupByExclude();

        return $dbal;
    }
}
