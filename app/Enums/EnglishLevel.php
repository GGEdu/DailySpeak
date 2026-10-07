<?php

namespace App\Enums;

/**
 * CEFR level the user is currently working at.
 */
enum EnglishLevel: string
{
    case B1 = 'B1';
    case B2 = 'B2';
    case C1 = 'C1';

    /**
     * Get the CEFR name of the level.
     */
    public function label(): string
    {
        return match ($this) {
            self::B1 => 'Intermediate',
            self::B2 => 'Upper-intermediate',
            self::C1 => 'Advanced',
        };
    }

    /**
     * Get what a speaker at this level can do, to help users pick theirs.
     */
    public function description(): string
    {
        return match ($this) {
            self::B1 => 'You can talk about familiar topics and give simple reasons for your opinions.',
            self::B2 => 'You can argue a point of view on current affairs, explaining pros and cons.',
            self::C1 => 'You express yourself fluently and precisely, even on complex subjects.',
        };
    }
}
