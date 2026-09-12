<?php

namespace App\Exceptions;

use Exception;

class YandexParseException extends Exception
{
    public const UNREACHABLE = 'unreachable';
    public const CAPTCHA = 'captcha';
    public const MARKUP_CHANGED = 'markup_changed';
    public const EMPTY = 'empty';
    public const NOT_FOUND = 'not_found';
    public const INVALID_URL = 'invalid_url';

    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function unreachable(): self
    {
        return new self(self::UNREACHABLE, 'Не удалось получить страницу Яндекс.Карт. Попробуйте позже.');
    }

    public static function captcha(): self
    {
        return new self(self::CAPTCHA, 'Яндекс показал защиту от ботов (капчу). Повторите попытку позже или смените IP/прокси.');
    }

    public static function markupChanged(): self
    {
        return new self(self::MARKUP_CHANGED, 'Разметка или данные карточки Яндекс.Карт изменились — парсер не нашёл ожидаемые поля.');
    }

    public static function empty(): self
    {
        return new self(self::EMPTY, 'Яндекс вернул пустой ответ.');
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, 'Организация не найдена или карточка недоступна.');
    }

    public static function invalidUrl(string $message = 'Некорректная ссылка на карточку Яндекс.Карт.'): self
    {
        return new self(self::INVALID_URL, $message);
    }
}
