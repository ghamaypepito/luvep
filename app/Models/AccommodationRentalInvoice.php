<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccommodationRentalInvoice extends Model
{
    public const KINDS = ['move-in' => 'Move-In Invoice', 'overdue' => 'Overdue Payment Invoice'];

    protected $fillable = ['tenant_id', 'created_by', 'kind', 'submission_key', 'submission_hash', 'reference', 'details', 'pdf_path'];

    protected $hidden = ['submission_key', 'submission_hash', 'pdf_path'];

    protected $casts = ['details' => 'array'];
}
