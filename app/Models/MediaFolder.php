<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaFolder extends Model
{
    public const PURCHASE_INVOICE_PREFIX = 'purchase-invoice:';

    protected $guarded = [];

    public function isPurchaseInvoice(): bool
    {
        return str_starts_with((string) $this->ownerRoot()->client_key, self::PURCHASE_INVOICE_PREFIX);
    }

    public function ownerClient(): Lead|Tenant|null
    {
        $root = $this->ownerRoot();

        return $root->lead ?? $root->tenant ?? ($root->isPurchaseInvoice()
            ? $root->tenant()->withTrashed()->first()
            : null);
    }

    public function root()
    {
        return $this->belongsTo(self::class, 'root_id');
    }

    public function ownerRoot(): self
    {
        return $this->root_id ? $this->root : $this;
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function shares()
    {
        return $this->hasMany(MediaShare::class, 'folder_id');
    }

    public function files()
    {
        return $this->hasMany(MediaFile::class, 'folder_id');
    }

    public function subtree()
    {
        return self::where(fn ($q) => $q->whereKey($this->id)->orWhere('ancestry', 'like', $this->ancestry.$this->id.'/%'));
    }
}
