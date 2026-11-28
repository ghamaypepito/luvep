<?php

namespace App\Services\Accommodation;

use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ComposeContacts
{
    public const TYPES = ['tenant', 'manager', 'owner'];

    public function search(string $type, string $term): Collection
    {
        [$query, $fields] = $this->query($type);
        foreach (preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $pattern = '%'.strtr(mb_strtolower($word), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $query->where(function (Builder $query) use ($fields, $pattern) {
                foreach ($fields as $field) {
                    $query->orWhereRaw("LOWER({$field}) LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }

        return $query->orderBy($fields[0])->orderBy('id')->limit(15)->get()
            ->map(fn ($row) => $this->contact($row, $type))
            ->filter(fn (array $contact) => filter_var($contact['email'], FILTER_VALIDATE_EMAIL))->values();
    }

    public function resolve(array $keys): Collection
    {
        $grouped = collect($keys)->groupBy(fn (string $key) => explode(':', $key, 2)[0]);

        return $grouped->flatMap(function (Collection $keys, string $type) {
            [$query] = $this->query($type);

            return $query->whereKey($keys->map(fn (string $key) => explode(':', $key, 2)[1])->all())
                ->get()->map(fn ($row) => $this->contact($row, $type));
        })->filter(fn (array $contact) => filter_var($contact['email'], FILTER_VALIDATE_EMAIL))->values();
    }

    private function query(string $type): array
    {
        if ($type === 'tenant') {
            return [Tenant::query()->select(['id', 'property_id', 'display_name_override', 'first_name', 'family_name', 'email'])
                ->with('property:id,name,address')->whereNotNull('email')->where('email', '!=', ''),
                ['first_name', 'family_name', 'display_name_override', 'email']];
        }

        $prefix = $type === 'manager' ? 'property_manager' : 'property_owner';
        $fields = ["{$prefix}_name", "{$prefix}_email"];

        return [Property::query()->select(['id', 'name', 'address', ...$fields])->where('is_active', true)
            ->whereNotNull($fields[1])->where($fields[1], '!=', ''), $fields];
    }

    private function contact(Tenant|Property $row, string $type): array
    {
        $property = $row;
        if ($type === 'tenant') {
            $name = $row->display_name;
            $email = $row->email;
            $property = $row->property;
        } elseif ($type === 'manager') {
            $name = $row->property_manager_name;
            $email = $row->property_manager_email;
        } else {
            $name = $row->property_owner_name;
            $email = $row->property_owner_email;
        }

        return [
            'id' => $type.':'.$row->id,
            'name' => $name ?: $email,
            'email' => strtolower(trim((string) $email)),
            'property' => $property?->address ?: $property?->name,
        ];
    }
}
