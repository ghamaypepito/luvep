<?php

namespace App\Services\Accommodation;

use Illuminate\Validation\Rule;

final class WelcomePackages
{
    public const SERVICE_NOTE = 'All prices include purchasing, transportation, preparation and setup services.';

    public const CATALOGUE = [
        'kitchenware' => [
            'name' => 'Kitchenware Package',
            'price' => 250,
            'inclusions' => [
                'Rice cooker, toaster, frying pan, saucepan, spatula, tongs, kitchen knife, chopping board, peeler, strainer, plates, bowls, spoons, forks, mugs and drinking glasses.',
                'Quantities will be adjusted according to the client’s household size.',
            ],
        ],
        'grocery' => [
            'name' => 'Grocery Package',
            'price' => 150,
            'inclusions' => [
                'Rice, sliced bread, eggs (2 dozen) and toilet tissue.',
                'Vegetables: potatoes, carrots, onions, garlic, tomatoes, cabbage and broccoli.',
                'Fruits: bananas, apples and oranges.',
                'Condiments: cooking oil, salt, black pepper, sugar, soy sauce, vinegar and tomato sauce.',
            ],
        ],
        'bedding' => [
            'name' => 'Bedding Essentials Package',
            'price' => 200,
            'inclusions' => ['Mattress protector, fitted sheet, flat sheet, two pillows, two pillowcases, duvet and duvet cover.'],
        ],
    ];

    public static function rules(): array
    {
        return [
            'arriving_from_overseas' => ['nullable', 'in:yes,no'],
            'welcome_packages' => ['exclude_unless:arriving_from_overseas,yes', 'nullable', 'array', 'list', 'max:3'],
            'welcome_packages.*' => ['exclude_unless:arriving_from_overseas,yes', 'required', 'string', 'distinct', Rule::in(array_keys(self::CATALOGUE))],
        ];
    }

    public static function snapshot(array $selection): array
    {
        $packages = array_intersect_key(self::CATALOGUE, array_flip($selection));

        return ['packages' => array_values($packages), 'total' => array_sum(array_column($packages, 'price')), 'service_note' => self::SERVICE_NOTE];
    }
}
