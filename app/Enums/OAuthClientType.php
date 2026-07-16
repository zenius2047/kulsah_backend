<?php

namespace App\Enums;

enum OAuthClientType: string
{
    case Web = 'web';
    case Android = 'android';
    case Ios = 'ios';

    public function label(): string
    {
        return match ($this) {
            self::Web => 'Web',
            self::Android => 'Android',
            self::Ios => 'iOS',
        };
    }

    public function isMobile(): bool
    {
        return $this !== self::Web;
    }

    public static function values(): array
    {
        return array_map(
            static fn (self $clientType): string => $clientType->value,
            self::cases(),
        );
    }
}
