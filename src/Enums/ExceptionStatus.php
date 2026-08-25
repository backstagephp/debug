<?php

namespace Backstage\Debug\Enums;

/**
 * What somebody decided about a problem, which is what the exceptions table is
 * narrowed by. It hangs off the fingerprint rather than off a single row: a
 * decision is about a failure, not about one occurrence of it.
 */
enum ExceptionStatus: string
{
    /**
     * Known and not worth looking at. Occurrences keep being recorded, they are
     * only kept out of the way until somebody asks for them.
     */
    case Ignored = 'ignored';

    /**
     * Dealt with. Everything up to the moment it was marked is out of the way;
     * anything that happens after it is a fix that did not hold, so it comes
     * back into the table on its own.
     */
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Ignored => 'Ignored',
            self::Fixed => 'Fixed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Ignored => 'gray',
            self::Fixed => 'success',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Ignored => 'lucide-eye-off',
            self::Fixed => 'lucide-check',
        };
    }
}
