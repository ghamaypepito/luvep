<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EducationRsvpContent
{
    public function defaults(): array
    {
        return [
            'hero' => ['image' => '/education-rsvp/assets/campus.webp', 'image_alt' => 'Students sharing a conversation on campus', 'heading' => 'Choose your event.', 'description' => 'Find your school below, then let us know you’ll be there. A simple hello can be the start of your New Zealand chapter.'],
            'destination' => ['image' => '/education-rsvp/assets/waterfront.webp', 'image_alt' => 'Young adults walking beside Wellington harbour', 'caption' => "Start with a conversation.\nSee where it takes you."],
            'form' => ['image' => '/education-rsvp/assets/conversation.webp', 'image_alt' => 'A conversation about education opportunities', 'heading' => 'I will attend.', 'description' => 'Leave your details and we’ll send your event confirmation to your inbox.'],
            'confirmation' => ['image' => '/education-rsvp/assets/library.webp', 'image_alt' => 'Friends exploring study options together', 'heading' => 'You’re on the list.', 'message' => 'Your RSVP is saved.'],
            'information' => ['heading' => 'Before you attend.', 'question_1' => 'Is this programme registration?', 'answer_1' => 'No. Confirm attendance saves your RSVP for the event. If you later register for a programme using matching verified contact details, your RSVP can be added to your applicant journey.', 'question_2' => 'What if the date isn’t confirmed?', 'answer_2' => 'You can RSVP now. We’ll email verified RSVP contacts when the schedule is confirmed. Date-based reminders wait until an exact future time is finalized.', 'question_3' => 'Can I ask a question first?', 'answer_3' => 'Contact the Education team and include your school’s name.', 'footer' => 'Education · Your New Zealand chapter'],
        ];
    }

    public function schema(): array
    {
        $field = fn ($label, $max = 120, $type = 'text') => ['label' => $label, 'max' => $max, 'type' => $type];

        return [
            'hero' => ['label' => 'Event page', 'help' => 'The main photo, heading and introduction above the school list.', 'fields' => ['heading' => $field('Main heading'), 'description' => $field('Introduction', 600, 'textarea'), 'image_alt' => $field('Photo description', 200)]],
            'destination' => ['label' => 'New Zealand photo panel', 'help' => 'The smaller photo and caption alongside the main image.', 'fields' => ['caption' => $field('Photo caption', 200, 'textarea'), 'image_alt' => $field('Photo description', 200)]],
            'form' => ['label' => 'RSVP form', 'help' => 'Shown after someone chooses their school event.', 'fields' => ['heading' => $field('Form heading'), 'description' => $field('Form introduction', 600, 'textarea'), 'image_alt' => $field('Photo description', 200)]],
            'confirmation' => ['label' => 'Confirmation screen', 'help' => 'Shown after attendance has been confirmed.', 'fields' => ['heading' => $field('Confirmation heading'), 'message' => $field('Message after the attendee’s name', 600, 'textarea'), 'image_alt' => $field('Photo description', 200)]],
            'information' => ['label' => 'Helpful information & footer', 'help' => 'Questions and answers at the bottom of the page.', 'fields' => ['heading' => $field('Section heading'), 'question_1' => $field('First question', 160), 'answer_1' => $field('First answer', 1200, 'textarea'), 'question_2' => $field('Second question', 160), 'answer_2' => $field('Second answer', 1200, 'textarea'), 'question_3' => $field('Third question', 160), 'answer_3' => $field('Third answer', 1200, 'textarea'), 'footer' => $field('Footer text', 180)]],
        ];
    }

    public function get(): array
    {
        $data = $this->defaults();
        foreach ($data as $section => $defaults) {
            $saved = Setting::get('education_rsvp_content_'.$section, []);
            if (is_array($saved)) {
                foreach ($defaults as $key => $value) {
                    if (isset($saved[$key]) && is_string($saved[$key])) {
                        $data[$section][$key] = $saved[$key];
                    }
                }
            }
        }

        return $data;
    }

    public function save(Request $request, string $section): void
    {
        $schema = $this->schema();
        abort_unless(isset($schema[$section]), 404);
        $rules = [];
        foreach ($schema[$section]['fields'] as $key => $field) {
            $rules[$key] = ['required', 'string', 'max:'.$field['max']];
        }
        if (isset($this->defaults()[$section]['image'])) {
            $rules['image'] = ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'];
        }
        $data = $request->validate($rules);
        unset($data['image']);
        $path = null;
        if ($request->hasFile('image') && isset($rules['image'])) {
            $path = $request->file('image')->store('education/rsvp-content/'.$section, 'public');
            abort_unless($path, 500, 'The photo could not be saved. Please try again.');
            $data['image'] = Storage::disk('public')->url($path);
        }
        try {
            DB::transaction(function () use ($section, $data) {
                $key = 'education_rsvp_content_'.$section;
                $row = Setting::where('key', $key)->lockForUpdate()->first();
                $saved = $row ? json_decode($row->value, true) : [];
                $values = array_merge($this->defaults()[$section], is_array($saved) ? array_intersect_key($saved, $this->defaults()[$section]) : [], $data);
                Setting::set($key, $values, 'json');
                ActivityLog::record('education.rsvp.content_updated', ['portal' => 'education', 'properties' => ['section' => $section], 'description' => 'Updated shared RSVP page content']);
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }throw $e;
        }
    }
}
