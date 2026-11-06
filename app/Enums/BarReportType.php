<?php

namespace App\Enums;

enum BarReportType: string
{
    case AVERAGE_PREPARATION_TIME_ITEMS = 'average-preparation-time-for-items';
    case AVERAGE_PREPARATION_TIME_PER_AGENT = 'average-preparation-time-of-each-item-per-agent';
    case AVERAGE_TAKE_NOTE_TIME_ITEMS = 'average-intake-time-for-items';
    case AVERAGE_TAKE_NOTE_TIME_PER_AGENT = 'average-intake-time-of-each-item-per-agent';
    case AVERAGE_SERVE_READY_TIME = 'average-time-to-serve-a-ready-item';
    case AVERAGE_SERVE_READY_TIME_PER_AGENT = 'average-time-to-serve-a-ready-item-per-agent';
    case AVERAGE_GLOBAL_SERVE_TIME = 'overall-average-time-to-serve-an-item';
    case REJECTION_RATIO_PER_ITEM = 'rejection-ratio-of-each-item';
    case REJECTION_RATIO_PER_AGENT = 'rejection-ratio-of-each-item-per-agent';
    case BAD_PREPARATION_RATIO_PER_ITEM = 'badly-prepared-ratio-of-each-item';
    case BAD_PREPARATION_RATIO_PER_AGENT = 'badly-prepared-ratio-per-agent';
    case ALL = 'all';

    public function label(): string
    {
        return match ($this) {
            self::AVERAGE_PREPARATION_TIME_ITEMS => 'Temps de préparation (En cours ➔ Prêt)',
            self::AVERAGE_PREPARATION_TIME_PER_AGENT => 'Temps de préparation par agent (En cours ➔ Prêt)',
            self::AVERAGE_TAKE_NOTE_TIME_ITEMS => 'Temps de prise en compte (Transféré ➔ En cours)',
            self::AVERAGE_TAKE_NOTE_TIME_PER_AGENT => 'Temps de prise en compte par agent (Transféré ➔ En cours)',
            self::AVERAGE_SERVE_READY_TIME => 'Temps de service (Prêt ➔ Servi)',
            self::AVERAGE_SERVE_READY_TIME_PER_AGENT => 'Temps de service par agent (Prêt ➔ Servi)',
            self::AVERAGE_GLOBAL_SERVE_TIME => 'Temps global de service (Transféré ➔ Servi)',
            self::REJECTION_RATIO_PER_ITEM => 'Ratio de rejet (Rejeté)',
            self::REJECTION_RATIO_PER_AGENT => 'Ratio de rejet par agent (Rejeté)',
            self::BAD_PREPARATION_RATIO_PER_ITEM => 'Ratio mal servi/confectionné (Rejet du prêt)',
            self::BAD_PREPARATION_RATIO_PER_AGENT => 'Ratio mal servi/confectionné par agent (Rejet du prêt)',
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
