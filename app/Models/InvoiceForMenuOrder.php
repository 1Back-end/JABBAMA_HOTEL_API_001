<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class InvoiceForMenuOrder extends Model
{
    use HasFactory, SoftDeletes;
    protected $table = 'invoices_for_menu_orders';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    // Champs remplissables en masse
    protected $fillable = [
        'uuid',
        'code',
        'sequence',
        'date_fact',
        'amount',
        'type',
        'created_by',
        'updated_by',
        'order_menu_restaurant_uuid',
    ];

    // Casts
    protected $casts = [
        'date_fact' => 'datetime',
        'amount' => 'integer',
        'type' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
            $model->code = self::generateCode();
            $model->sequence = self::generateSequence();
        });
    }

    public static function generateCode(): string
    {
        $datePart = now()->format('Ydm');
        $prefix = '#' . $datePart;

        // Chercher le dernier code global (pas par jour)
        $last = self::withTrashed()->orderBy('created_at', 'desc')->first();

        if ($last && preg_match('/(\d{6})$/', $last->code, $matches)) {
            $number = (int) $matches[1] + 1;
        } else {
            $number = 1;
        }

        return $prefix . str_pad($number, 6, '0', STR_PAD_LEFT);
    }

    public static function generateSequence(): int
    {
        $last = self::withTrashed()->orderBy('sequence', 'desc')->first();

        if ($last) {
            return $last->sequence + 1;
        }

        return 1;
    }

    public function orderMenu()
    {
        return $this->belongsTo(OrderMenuRestaurant::class, 'order_menu_restaurant_uuid', 'uuid');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

//    public function regulations()
//    {
//        return $this->hasMany(RegulationForOrderMenu::class, 'invoice_uuid', 'uuid');
//    }
}
