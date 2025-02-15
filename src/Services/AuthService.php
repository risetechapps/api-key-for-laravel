<?php

namespace RiseTechApps\ApiKey\Services;

class AuthService
{
    public static string $ENABLE = 'enabled';
    public static string $DISABLE = 'disabled';
    public static string $BLOCKED = 'blocked';


    public static string $CLIENT = 'client';
    public static string $EMPLOYEE = 'employee';

    public static function statusLogin(): array
    {
        return [
            static::$ENABLE,
            static::$DISABLE,
            static::$BLOCKED,
        ];
    }

    public static function genreProfile(): array
    {
        return ["MASCULINE", "FEMALE", "OTHER"];
    }

    public static function maritalStatusProfile(): array
    {
        return ["SINGLE", "MARRIED", "WIDOWER", "JUDICIALLY SEPARATED"];
    }

    public static function permission(): array
    {
        return [

        ];
    }


    public static function roles(): array
    {
        return [
            static::$EMPLOYEE,
            static::$CLIENT,
        ];
    }
}
