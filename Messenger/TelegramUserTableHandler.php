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
use BaksDev\Core\Twig\TemplateExtension;
use BaksDev\Telegram\Api\TelegramSendMessages;
use BaksDev\Telegram\Bot\Messenger\TelegramDeleteMessageHandler;
use BaksDev\Telegram\Bot\Messenger\TelegramEndpointMessage\TelegramEndpointMessage;
use BaksDev\Telegram\Builder\ReplyKeyboardMarkup\ReplyKeyboardButton;
use BaksDev\Telegram\Builder\ReplyKeyboardMarkup\ReplyKeyboardMarkup;
use BaksDev\Telegram\Request\Type\TelegramRequestCallback;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Users\UsersTable\Security\Table\Role as UserTableRole;
use BaksDev\Users\UsersTableTelegram\Repository\UserTableInfo\UserTableInfoRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Twig\Environment;

/** Отправляет табель */
#[AsMessageHandler()]
final readonly class TelegramUserTableHandler
{
    private CacheInterface $cache;

    public function __construct(
        #[Target('telegramLogger')] private LoggerInterface $logger,
        private AppCacheInterface $appCache,
        private ActiveProfileByAccountTelegramInterface $activeProfileByAccountTelegram,
        private UserTableInfoRepository $tableByRepository,
        private TemplateExtension $templateExtension,
        private Environment $environment,
        private UrlGeneratorInterface $router,
        private ?TelegramSendMessages $telegramSendMessage,
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

        /** Соответствие ключа кнопки для запроса */
        if(false === ($telegramRequest->getCall() === UserTableRole::KEY))
        {
            return;
        }

        $tableUrl = $this->router->generate(name: 'users-table:admin.table.index', referenceType: UrlGeneratorInterface::ABSOLUTE_URL);

        /** Профиль пользователя по id телеграм чата */
        $profile = $this->activeProfileByAccountTelegram->findByChat($telegramRequest->getChatId());

        if(false === ($profile instanceof UserProfileUid))
        {
            $this->logger->warning(__CLASS__.':'.__LINE__.'Запрос от не авторизированного пользователя');
            return;
        }

        $cacheKey = md5($telegramRequest->getChatId().$profile);

        /**
         * Идентификатор профиля, к которому есть доступ
         * @var UserProfileUid|null $authority
         */
        $authority = $this->cache->getItem($cacheKey)->get();

        if(is_null($authority))
        {
            $this->logger->warning(__CLASS__.':'.__LINE__.'Не найден идентификатор $authority', ['$authority' => $authority]);
            return;
        }

        /** Информация о табеле сотрудника */
        $userTableInfo = $this->tableByRepository
            ->onUserProfile($profile)
            ->onAuthority($authority)
            ->toArray();

        /** Готовим сообщение для отправки */
        $this
            ->telegramSendMessage
            ->chanel($telegramRequest->getChatId());

        if(false === $userTableInfo)
        {
            $this->logger->warning(__CLASS__.':'.__LINE__.'Табель учета выполненных работ не найден', ['$profile' => $profile, '$authority' => $authority]);

            /** Клавиатура */
            $inlineKeyboard = new ReplyKeyboardMarkup;
            /** Кнопка назад */
            $inlineKeyboard->addNewRow(
                (new ReplyKeyboardButton)
                    ->setText('Выход')
                    ->setCallbackData(TelegramDeleteMessageHandler::DELETE_KEY)
            );

            /** Сообщаем об ошибке */
            $this
                ->telegramSendMessage
                ->message('<b>Табель учета выполненных работ не найден</b>')
                ->markup($inlineKeyboard)
                ->delete([$telegramRequest->getId(), $telegramRequest->getLast()])
                ->send();

            $message->complete();

            return;
        }

        $template = $this->templateExtension->extends('@users-table-telegram:bot/table.html.twig');

        try
        {
            $render = $this->environment->render($template, [
                'tableUserInfo' => $userTableInfo,
                'tableUrl' => $tableUrl,
            ]);
        }
        catch(\Exception $exception)
        {
            $this->logger->critical(__CLASS__.':'.__LINE__.'Ошибка рендера шаблона @users-table-telegram:bot/table.html.twig', ['chatId' => $telegramRequest->getChatId()]);
            return;
        }

        $inlineKeyboard = $this->keyboard();

        if(is_null($inlineKeyboard))
        {
            /** Клавиатура */
            $inlineKeyboard = new ReplyKeyboardMarkup;
            /** Кнопка назад */
            $inlineKeyboard->addNewRow(
                (new ReplyKeyboardButton)
                    ->setText('Выход')
                    ->setCallbackData(TelegramDeleteMessageHandler::DELETE_KEY)
            );

            /** Сообщаем об ошибке */
            $this
                ->telegramSendMessage
                ->message('<b>Нет доступа к секциям это раздела</b>')
                ->markup($inlineKeyboard)
                ->send();

            $message->complete();

            return;
        }

        $this
            ->telegramSendMessage
            ->message($render)
            ->markup($inlineKeyboard)
            ->delete([$telegramRequest->getId(), $telegramRequest->getLast()])
            ->send();

        $message->complete();
    }

    private function keyboard(): array|null
    {
        $inlineKeyboard = new ReplyKeyboardMarkup;

        /** Кнопка назад */
        $backButton = new ReplyKeyboardButton;
        $backButton
            ->setText('Выход')
            ->setCallbackData(TelegramDeleteMessageHandler::DELETE_KEY);

        $inlineKeyboard->addNewRow($backButton);

        return $inlineKeyboard->build();
    }
}

