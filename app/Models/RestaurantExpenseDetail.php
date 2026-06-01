<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class RestaurantExpenseDetail extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'restaurant_expense_details';

    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'payment_regulation_uuid',
        'restaurant_expense_family_uuid',
        'name',
        'description',
        'created_by',
        'updated_by',
    ];


    public function paymentRegulation()
    {
        return $this->belongsTo(
            PaymentRegulation::class,
            'payment_regulation_uuid',
            'uuid'
        );
    }

    public function expenseFamily()
    {
        return $this->belongsTo(
            RestaurantExpenseFamily::class,
            'restaurant_expense_family_uuid',
            'uuid'
        );
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
