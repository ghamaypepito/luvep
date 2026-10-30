<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SimpleEducationRegistration
{
    public function validate(Request $request): array
    {
        $input = $request->all();
        foreach (['full_name', 'email', 'phone', 'whatsapp', 'country', 'event_name'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $input[$field] = trim($input[$field]);
            }
        }
        if (isset($input['full_name']) && is_string($input['full_name'])) {
            $input['full_name'] = preg_replace('/\s+/u', ' ', $input['full_name']);
        }
        $managed = $request->filled('visit_id');
        $data = Validator::make($input, [
            'form_version' => 'required|in:simple', 'submission_id' => 'required|uuid',
            'full_name' => 'required|string|max:150', 'email' => 'required|email:rfc|max:255',
            'phone' => 'required|string|min:7|max:40', 'whatsapp_same_as_phone' => 'required|boolean',
            'whatsapp' => [Rule::excludeIf($request->boolean('whatsapp_same_as_phone')), 'required', 'string', 'min:7', 'max:40'],
            'country' => ['required', 'string', 'max:100', Rule::notIn(['__other__'])],
            'visit_id' => 'sometimes|nullable|integer|min:1',
            'event_name' => [Rule::excludeIf($managed), 'required', 'string', 'max:180'],
            'consent' => 'required|accepted', 'website' => 'nullable|string|max:0',
        ])->validate();
        $parts = explode(' ', $data['full_name'], 2);
        if (mb_strlen($parts[0]) > 100 || mb_strlen($parts[1] ?? '') > 100) {
            throw ValidationException::withMessages(['full_name' => 'Please keep each name part within 100 characters.']);
        }
        $data['first_name'] = $parts[0];
        $data['last_name'] = $parts[1] ?? '';
        $data['whatsapp_same_as_phone'] = (bool) $data['whatsapp_same_as_phone'];
        $data['whatsapp'] = $data['whatsapp_same_as_phone'] ? $data['phone'] : $data['whatsapp'];
        $data += ['event_location_only' => true, 'goal' => null, 'planning_timeline' => null, 'education_level' => null];
        if (! $managed) {
            $data += ['school' => null, 'event_date' => null, 'event_hour' => 7, 'event_minute' => 0, 'event_period' => 'PM', 'event_timezone' => 'Asia/Manila'];
        }

        return $data;
    }
}
