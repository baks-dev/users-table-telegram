# BaksDev UsersTable

[![Version](https://img.shields.io/badge/version-7.2.0-blue)](https://github.com/baks-dev/users-table-telegram/releases)
![php 8.4+](https://img.shields.io/badge/php-min%208.4-red.svg)
[![packagist](https://img.shields.io/badge/packagist-green)](https://packagist.org/packages/baks-dev/users-table-telegram)

Модуль табельного учета пользователя

## Установка

``` bash
$ composer require baks-dev/users-table-telegram
```

## Дополнительно

Установка конфигурации и файловых ресурсов:

``` bash
$ php bin/console baks:assets:install
```

Изменения в схеме базы данных с помощью миграции

``` bash
$ php bin/console doctrine:migrations:diff
$ php bin/console doctrine:migrations:migrate
```

## Тестирование

``` bash
$ php bin/phpunit --group=users-table-telegram
```

## Лицензия ![License](https://img.shields.io/badge/MIT-green)

[README.md](README.md)
The MIT License (MIT). Обратитесь к [Файлу лицензии](LICENSE.md) за дополнительной информацией.
