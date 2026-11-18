<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\LeadTag;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AffiliateRegistrationController extends Controller
{
    private const SERVICES = ['Study in New Zealand', 'Immigration advice', 'English study', 'Accommodation', 'Help me explore my options'];

    public function show(Request $request, string $code)
    {
        $partner = $this->partner($code)->first();

        return response()->view('affiliate.register', [
            'partner' => $partner,
            'services' => self::SERVICES,
            'complete' => $request->session()->get('affiliate_registration_complete') === $code,
        ], $partner ? 200 : 404)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function store(Request $request, string $code)
    {
        $this->partner($code)->firstOrFail();
        $request->merge(collect($request->only(['first_name', 'last_name', 'email', 'phone', 'country']))
            ->map(fn ($value) => is_string($value) ? trim($value) : $value)->all());
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:150'],
            'phone' => ['required', 'string', 'max:35'],
            'country' => ['required', 'string', 'max:80'],
            'service' => ['required', Rule::in(self::SERVICES)],
            'consent' => ['required', 'accepted'],
        ]);
        $data['email'] = mb_strtolower($data['email']);

        try {
            Cache::lock('affiliate-registration:'.hash('sha256', $data['email']), 30)->block(5, function () use ($code, $data) {
                for ($attempt = 0; ; $attempt++) {
                    try {
                        DB::transaction(function () use ($code, $data) {
                            $partner = $this->partner($code)->lockForUpdate()->firstOrFail();
                            $existing = Lead::withTrashed()->whereRaw('LOWER(TRIM(email)) = ?', [$data['email']])->lockForUpdate()->first();
                            if ($existing) {
                                ActivityLog::record('affiliate.registration.received_existing', [
                                    'portal' => 'public', 'entity_type' => Lead::class, 'entity_id' => $existing->id,
                                    'description' => 'Affiliate enquiry received for an existing record. Original attribution retained.',
                                    'properties' => ['affiliate_id' => $partner->id, 'service' => $data['service'], 'contact_consent' => true],
                                ]);

                                return;
                            }
                            $lead = Lead::create([
                                'lead_id' => Lead::generateLeadId(), 'first_name' => $data['first_name'], 'last_name' => $data['last_name'],
                                'email' => $data['email'], 'phone' => $data['phone'], 'residence_country' => $data['country'],
                                'agent_id' => $partner->id, 'original_affiliate_id' => $partner->id, 'referred_at' => now(),
                                'source' => 'affiliate-link', 'status' => 'New Leads', 'stage' => 'New Leads',
                            ]);
                            $tag = LeadTag::firstOrCreate(['slug' => 'affiliate-partner-'.$partner->id], [
                                'name' => 'Affiliate partner: '.mb_substr($partner->name, 0, max(1, 38 - mb_strlen($partner->referral_code))).' · '.$partner->referral_code,
                                'color' => 'green',
                            ]);
                            $lead->tags()->syncWithoutDetaching([$tag->id => ['user_id' => null]]);
                            LeadNote::create([
                                'lead_id' => $lead->id, 'user_id' => null, 'author_name' => 'Public registration', 'author_role' => 'public',
                                'body' => 'Affiliate enquiry: '.$data['service']."\nReferred by ".$partner->name.'. Contact consent recorded with this registration.',
                            ]);
                            ActivityLog::record('affiliate.registration.created', [
                                'portal' => 'public', 'entity_type' => Lead::class, 'entity_id' => $lead->id,
                                'description' => 'Client registered through an Affiliate Partner link.',
                                'properties' => ['affiliate_id' => $partner->id, 'service' => $data['service'], 'contact_consent' => true, 'consent_text' => 'I agree to be contacted by EP and '.$partner->name.' about my enquiry.'],
                            ]);
                        });
                        break;
                    } catch (UniqueConstraintViolationException $error) {
                        // Lead reference or tag creation can race another intake path.
                        if ($attempt >= 2) {
                            throw $error;
                        }
                    }
                }
            });
        } catch (LockTimeoutException) {
            return back()->withInput($request->except(['consent', '_token']))->withErrors(['email' => 'Your registration is being processed. Please try again in a moment.']);
        }

        return redirect()->route('affiliate.registration', ['code' => $code])->with('affiliate_registration_complete', $code);
    }

    private function partner(string $code)
    {
        return User::where('referral_code', $code)->where('role', 'agent')->where('is_active', true);
    }
}
