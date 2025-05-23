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

namespace BaksDev\Users\UsersTableTelegram\Messenger;

use BaksDev\Auth\Telegram\Repository\ActiveProfileByAccountTelegram\ActiveProfileByAccountTelegramInterface;
use BaksDev\Core\Cache\AppCacheInterface;
use BaksDev\Menu\Admin\Repository\MenuAdmin\MenuAdminInterface;
use BaksDev\Menu\Admin\Repository\MenuAdmin\MenuAdminPathResult;
use BaksDev\Menu\Admin\Repository\MenuAdmin\MenuAdminResult;
use BaksDev\Telegram\Api\TelegramSendMessages;
use BaksDev\Telegram\Bot\Messenger\TelegramDeleteMessageHandler;
use BaksDev\Telegram\Bot\Messenger\TelegramEndpointMessage\TelegramEndpointMessage;
use BaksDev\Telegram\Bot\Messenger\TelegramMenuAuthorityHandler;
use BaksDev\Telegram\Bot\Repository\SecurityProfileIsGranted\TelegramSecurityInterface;
use BaksDev\Telegram\Builder\ReplyKeyboardMarkup\ReplyKeyboardButton;
use BaksDev\Telegram\Builder\ReplyKeyboardMarkup\ReplyKeyboardMarkup;
use BaksDev\Telegram\Request\Type\TelegramRequestCallback;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Раздел - Пользователи
 *
 * next @see TelegramUserTableHandler
 */
#[AsMessageHandler()]
final class TelegramMenuUsersHandler
{
    public const string KEY = 'tTdsEv';

    /** Заголовок раздела */
    private string|null $sectionHeader = null;

    /** Идентификатор раздела */
    private string|null $sectionId = null;

    private CacheInterface $cache;

    public function __construct(
        #[Target('telegramLogger')] private readonly LoggerInterface $logger,
        private readonly AppCacheInterface $appCache,
        private readonly ActiveProfileByAccountTelegramInterface $activeProfileByAccountTelegram,
        private readonly TelegramSecurityInterface $telegramSecurity,
        private readonly MenuAdminInterface $MenuAdmin,
        private readonly TelegramSendMessages|null $telegramSendMessage,
    )
    {
        $this->cache = $appCache->init('telegram');
    }

    public function __invoke(TelegramEndpointMessage $message): void
    {
        $telegramRequest = $message->getTelegramRequest();

        /** Проверка на тип запроса */
        if(false === ($telegramRequest instanceof TelegramRequestCallback))
        {
            return;
        }

        $this
            ->telegramSendMessage
            ->chanel($telegramRequest->getChatId());

        /** Получаем идентификатор секции меню из callback_data */
        $this->sectionId = $telegramRequest->getIdentifier();

        if(is_null($this->sectionId))
        {
            $this->logger->critical('Ошибка получения идентификатора раздела', [$telegramRequest->getCall()]);
            return;
        }

        /** Проверка идентификатора кнопки */
        if(false === str_contains($telegramRequest->getCall(), TelegramMenuAuthorityHandler::KEY))
        {
            return;
        }

        /** Профиль пользователя по id телеграм чата */
        $profile = $this->activeProfileByAccountTelegram
            ->findByChat($telegramRequest->getChatId());

        if(false === ($profile instanceof UserProfileUid))
        {
            $this->logger->critical('Запрос от не авторизированного пользователя');
            return;
        }

        $cacheKey = $profile.':'.$telegramRequest->getChatId();

        /**
         * Идентификатор профиля, к которому есть доступ
         * @var UserProfileUid|null $authority
         */
        $authority = $this->cache->getItem($cacheKey)->get();

        if(is_null($authority))
        {
            $this->logger->critical('Не найден идентификатор $authority');
            return;
        }

        $authorityMenu = $this->authorityMenuSections($profile, $authority);

        /** Меню пустое если у пользователя нет доступов */
        if(is_null($authorityMenu))
        {
            $this->logger->critical('У данного профиля нет доступа к разделу меню', [$profile, $this->sectionHeader]);

            /** Клавиатура */
            $inlineKeyboard = new ReplyKeyboardMarkup;
            /** Кнопка назад */
            $inlineKeyboard->addNewRow(
                (new ReplyKeyboardButton)
                    ->setText('Выход')
                    ->setCallbackData(TelegramDeleteMessageHandler::KEY)
            );

            /** Сообщаем об ошибке */
            $this
                ->telegramSendMessage
                ->message('<b>Нет доступа к секциям это раздела</b>')
                ->markup($inlineKeyboard)
                ->delete([$telegramRequest->getId(), $telegramRequest->getLast()])
                ->send();

            // $message->complete();

            return;
        }

        $inlineKeyboard = $this->keyboard($authorityMenu, $authority);

        if(is_null($inlineKeyboard))
        {
            $this->logger->critical('Ошибка создания клавиатуры для чата');

            /** Клавиатура */
            $inlineKeyboard = new ReplyKeyboardMarkup;
            /** Кнопка назад */
            $inlineKeyboard->addNewRow(
                (new ReplyKeyboardButton)
                    ->setText('Назад')
                    ->setCallbackData(TelegramDeleteMessageHandler::KEY)
            );

            /** Сообщаем об ошибке */
            $this
                ->telegramSendMessage
                ->message('<b>Внутренняя ошибка сервера. Обратитесь к администратору</b>')
                ->markup($inlineKeyboard)
                ->send();

            // $message->complete();

            return;
        }

        /** Отправляем действие с клавиатурой */
        $text = $this->sectionHeader ?? 'Выберите секцию раздела';
        $this
            ->telegramSendMessage
            ->message("<b>$text</b>")
            ->markup($inlineKeyboard)
            ->delete([$telegramRequest->getId(), $telegramRequest->getLast()])
            ->send();

        // $message->complete();
    }

    /**
     * Секции корневого раздела
     * @return array<int, MenuAdminPathResult>|null
     */
    private function authorityMenuSections(UserProfileUid|string $profile, UserProfileUid|string $authority): array|null
    {
        /** Получаем разделы и подразделы меню */
        $menu = $this->MenuAdmin->find();

        /**
         * Фильтруем по соответствию идентификатору раздела, полученного из callback_data
         */
        $menuSections = array_filter($menu, function(MenuAdminResult $menuRoot) {
            return (string) $menuRoot->getSectionId() === $this->sectionId;
        });

        /**
         * Перестроенное меню из секций разделов, с учетом наличие доступов по ролям
         * @var array<int, MenuAdminPathResult>|null $authorityMenu
         */
        $authoritySections = null;

        foreach($menuSections as $menuRoot)
        {
            /**
             * Фильтруем секции в каждом из разделов меню
             */
            $rootSections = $menuRoot->getPath();

            /** @var MenuAdminPathResult $section */
            $authRootSections = array_filter($rootSections, function(object $section) use (
                $profile,
                $authority,
                $menuRoot,
            ) {

                /** Доступ к секции по роли */
                $isGranted = $this->telegramSecurity->isGranted(
                    $profile,
                    $section->getRole(),
                    $authority
                );

                /** Если нет ссылки - это заголовок секции */
                $isSectionHeader = ($section->getHref() !== null);

                return true === $isGranted && true === $isSectionHeader;
            });

            /** Если есть доступ по роли - добавляем раздел с доступными секциями меню */
            if(false === empty($authRootSections))
            {
                $this->sectionHeader = $menuRoot->getName();
                $authoritySections = $authRootSections;
            }
        }

        return $authoritySections;
    }

    /**
     * @param array<int, MenuAdminPathResult> $menu
     */
    private function keyboard(array $menu): array|null
    {

        $inlineKeyboard = new ReplyKeyboardMarkup;

        foreach($menu as $menuSection)
        {
            $callbackData = $menuSection->getKey().'|';

            $button = new ReplyKeyboardButton;
            $button
                ->setText($menuSection->getName()) // название раздела меню
                ->setCallbackData($callbackData);

            $inlineKeyboard->addNewRow($button);
        }

        /** Кнопка назад */
        $backButton = new ReplyKeyboardButton;
        $backButton
            ->setText('Выход')
            ->setCallbackData(TelegramDeleteMessageHandler::KEY);

        $inlineKeyboard->addNewRow($backButton);

        return $inlineKeyboard->build();
    }
}

