<?php

namespace App\Models;

use App\Services\Finance\PortalInvoiceReceivables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class InvoiceReplacement extends Model
{
    protected $guarded = ['id'];

    public static function assertNumberIsNotSuperseded(string $number): void
    {
        if (static::where('previous_reference', PortalInvoiceReceivables::reference($number))->exists()) {
            throw ValidationException::withMessages(['invoice_number' => 'This invoice number was superseded and must remain in invoice history.']);
        }
    }
}
