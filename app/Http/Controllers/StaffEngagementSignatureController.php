<?php

namespace App\Http\Controllers;

use App\Services\Immigration\StaffEngagementSignature;
use Illuminate\Http\Request;

class StaffEngagementSignatureController extends Controller
{
    public function store(Request $request, StaffEngagementSignature $signatures, int $document)
    {
        return response()->json($signatures->upload($request, $document));
    }
}
