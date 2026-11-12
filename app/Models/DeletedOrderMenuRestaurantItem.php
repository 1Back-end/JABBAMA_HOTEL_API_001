<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeletedOrderMenuRestaurantItem extends Model
{
    use HasFactory,SoftDeletes,HasUuids;
    protected $table = 'deleted_order_menu_restaurant_items';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'order_menu_restaurant_uuid',
        'menus_restaurant_uuid',
        'cart_line_uuid',
        'quantity_ordered',
        'unit_price',
        'created_by',
        'updated_by',
        'deleted_by',
        'deletion_reason',
    ];

    /**
     * Relation avec la commande parente.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(OrderMenuRestaurant::class, 'order_menu_restaurant_uuid', 'uuid');
    }

    /**
     * Relation avec le menu du restaurant.
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(MenuRestaurant::class, 'menus_restaurant_uuid', 'uuid');
    }

    /**
     * Utilisateur ayant supprimé l'élément.
     */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * Utilisateur ayant créé l'enregistrement.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Utilisateur ayant mis à jour l'enregistrement.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
