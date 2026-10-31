<?php

namespace App\Enums;

enum RestaurantReportType: string
{
    case AVERAGE_COOKING_TIME_DISHES = 'average-cooking-time-for-dishes';
    case AVERAGE_COOKING_TIME_PER_AGENT = 'average-cooking-time-of-each-dish-per-agent';
    case AVERAGE_TAKE_NOTE_TIME_DISHES = 'average-intake-time-for-dishes';
    case AVERAGE_TAKE_NOTE_TIME_PER_AGENT = 'average-intake-time-of-each-dish-per-agent';
    case AVERAGE_SERVE_READY_TIME = 'average-time-to-serve-a-ready-dish';
    case AVERAGE_SERVE_READY_TIME_PER_AGENT = 'average-time-to-serve-a-ready-dish-per-agent';
    case AVERAGE_GLOBAL_SERVE_TIME = 'overall-average-time-to-serve-a-dish';
    case REJECTION_RATIO_PER_DISH = 'rejection-ratio-of-each-dish';
    case REJECTION_RATIO_PER_AGENT = 'rejection-ratio-of-each-dish-per-agent';
    case BAD_PREPARATION_RATIO_PER_DISH = 'badly-prepared-ratio-of-each-dish';
    case BAD_PREPARATION_RATIO_PER_AGENT = 'badly-prepared-ratio-per-agent';
    case ALL = 'all';

    public function label(): string
    {
        return match ($this) {
            self::AVERAGE_COOKING_TIME_DISHES => 'Temps de cuisson (En cours de préparation ➔ Prêt)',
            self::AVERAGE_COOKING_TIME_PER_AGENT => 'Temps de cuisson par agent (En cours de préparation ➔ Prêt)',
            self::AVERAGE_TAKE_NOTE_TIME_DISHES => 'Temps de prise en compte (Transféré ➔ En cours de préparation)',
            self::AVERAGE_TAKE_NOTE_TIME_PER_AGENT => 'Temps de prise en compte par agent (Transféré ➔ En cours de préparation)',
            self::AVERAGE_SERVE_READY_TIME => 'Temps de service (Prêt ➔ Servi)',
            self::AVERAGE_SERVE_READY_TIME_PER_AGENT => 'Temps de service par agent (Prêt ➔ Servi)',
            self::AVERAGE_GLOBAL_SERVE_TIME => 'Temps global de cuisson (Transféré ➔ Servi)',
            self::REJECTION_RATIO_PER_DISH => 'Ratio de rejet (Rejeté)',
            self::REJECTION_RATIO_PER_AGENT => 'Ratio de rejet par agent (Rejeté)',
            self::BAD_PREPARATION_RATIO_PER_DISH => 'Ratio mal confectionné (Rejet du prêt)',
            self::BAD_PREPARATION_RATIO_PER_AGENT => 'Ratio mal confectionné par agent (Rejet du prêt)',
            self::ALL => 'Tous les indicateurs',
        };
    }

    public static function safeLabel(?string $value): string
    {
        return self::tryFrom($value)?->label() ?? 'Inconnu';
    }

    public static function toArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn($case) => [$case->value => $case->label()])
            ->toArray();
    }
}
