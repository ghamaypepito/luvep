<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccommodationPurchaseInvoice extends Model
{
    protected $fillable = [
        'submission_key', 'submission_hash', 'tenant_id', 'property_id', 'folder_id', 'invoice_file_id', 'created_by',
        'reference', 'tenant_name', 'property_address', 'issue_date', 'due_date', 'total',
    ];

    protected $casts = ['issue_date' => 'date', 'due_date' => 'date', 'total' => 'decimal:2'];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class);
    }
}
