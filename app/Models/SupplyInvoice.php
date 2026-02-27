<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class SupplyInvoice extends Model
{
    use SoftDeletes;

    protected $table = 'supply_invoices';
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'supply_uuid',
        'invoice_number',
        'total_price',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected static function booted()
    {
        static::creating(function ($invoice) {
            // Générer UUID si pas défini
            if (empty($invoice->uuid)) {
                $invoice->uuid = (string) Str::uuid();
            }
            // Générer invoice_number si pas défini
            if (empty($invoice->invoice_number)) {
                $invoice->invoice_number = self::generateInvoiceNumber();
            }
        });
    }

    /**
     * Fonction pour générer un numéro de facture unique
     */
    public static function generateInvoiceNumber()
    {
        $prefix = '#';
        $date = date('Ymd');
        $random = mt_rand(1000, 9999);
        return "{$prefix}-{$date}-{$random}";
    }

    /**
     * Relation avec l'approvisionnement
     */
    public function supply()
    {
        return $this->belongsTo(Supply::class, 'supply_uuid', 'uuid');
    }

    /**
     * Relation avec les documents scannés
     */
    public function medias()
    {
        return $this->morphMany(Medias::class, 'mediable');
    }

    /**
     * Relation avec l'utilisateur qui a créé
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Relation avec l'utilisateur qui a mis à jour
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Calculer le prix unitaire d'un produit (si besoin)
     */
    public function unitPrice($quantity)
    {
        return $quantity > 0 ? $this->total_price / $quantity : 0;
    }
}
