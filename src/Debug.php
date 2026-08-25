<?php

namespace Backstage\Debug;

use Illuminate\Database\Eloquent\Model;

/**
 * The handful of things the logs and the Filament resources have to agree on,
 * read off the configuration in one place rather than in each of them.
 */
final class Debug
{
    /**
     * The model behind the `user_id` of an exception or a log line. It follows
     * the default authentication provider unless the configuration names one,
     * so an application that has not thought about it still gets the right
     * model.
     *
     * @return class-string<Model>
     */
    public static function userModel(): string
    {
        /** @var class-string<Model> $model */
        $model = config('debug.user.model')
            ?? config('auth.providers.users.model')
            ?? 'App\Models\User';

        return $model;
    }

    /**
     * The attribute a user is shown by.
     */
    public static function userNameAttribute(): string
    {
        return (string) config('debug.user.name_attribute', 'name');
    }

    /**
     * The columns a user is searched by, which are not the same thing as the
     * attribute they are shown by: a name composed of a first and a last name
     * has no column of its own to search.
     *
     * @return array<int, string>
     */
    public static function userSearchColumns(): array
    {
        /** @var array<int, string> $columns */
        $columns = config('debug.user.search_columns') ?: [self::userNameAttribute()];

        return $columns;
    }

    /**
     * Whether one of the four logs is switched on.
     */
    public static function records(string $log): bool
    {
        return (bool) config("debug.record.{$log}", true);
    }
}
