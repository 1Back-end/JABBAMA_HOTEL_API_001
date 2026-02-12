<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Country extends Model
{
    protected $fillable = [
        'name', 'iso3', 'iso2', 'phonecode', 'capital', 'currency', 'currency_symbol',
        'tld', 'native', 'region', 'subregion', 'timezones', 'translations',
        'latitude', 'longitude', 'emoji', 'emojiU', 'flag', 'wikiDataId',
    ];

    protected function flag(): Attribute
    {
        $iso = Str::lower($this->iso2);
        return Attribute::make(
            get: fn($value, array $attributes) => "https://flagcdn.com/w320/{$iso}.png",
            set: fn($value) => $value,
        );
    }

    protected function phonecode(): Attribute
    {
        return Attribute::make(
            get: fn($value, array $attributes) => Str::startsWith($value, '+') ? $value : '+' . $value,
            set: fn($value) => $value,
        );
    }
}
