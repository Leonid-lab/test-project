<?php

declare(strict_types=1);

namespace App\Enums;

enum ProcessStatusId: int
{
    case Running = 1;
    case Completed = 2;
    case Failed = 3;

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Запуск',
            self::Completed => 'Завершен',
            self::Failed => 'Ошибка',
        };
    }
}
