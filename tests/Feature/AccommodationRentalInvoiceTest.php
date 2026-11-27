<?php

namespace Tests\Feature;

use App\Models\AccommodationAgreement;
use App\Models\AccommodationRentalInvoice;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Support\WeeklyRentCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccommodationRentalInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/portal/accommodation/rental-invoices';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config(['modules.accommodation_finance_accounts' => ['exalt-finance@example.test']]);
    }

    public static function moveInAgreementTypes(): array
    {
        return [
            'standard flatmate' => ['standard_flatmate'],
            'short-term flatmate' => ['transient_flatmate'],
            'whole property' => ['whole_property'],
        ];
    }

    #[DataProvider('moveInAgreementTypes')]
    public function test_move_in_prefills_agreed_costs_and_packages_and_saves_the_edited_pdf_once(string $agreementType): void
    {
        $this->travelTo(Carbon::parse('2026-11-27 10:00', 'Pacific/Auckland'));
        $tenant = $this->tenant();
        $agreement = AccommodationAgreement::create([
            'tenant_id' => $tenant->id, 'property_id' => $tenant->property_id,
            'agreement_type' => $agreementType, 'client_name' => 'Sample Client',
            'property_address' => '10 Sample Road', 'status' => 'signed',
            'data' => [
                'costs' => ['bond' => '365.00', 'deposit' => '365.00', 'rent_advance' => '365.00'],
                'welcome_packages' => ['packages' => [['name' => 'Grocery Package', 'price' => 150]]],
            ],
        ]);
        $this->actingAs(User::factory()->create(['role' => 'accommodation']));
        $path = self::PATH.'/move-in';
        $form = $this->get($path.'?tenant='.$tenant->id)->assertOk()->inertiaProps();
        $this->assertSame('2026-11-29', $form['due_date']);
        $lines = $form['agreements'][0]['lines'];
        $this->assertSame(['Bond', 'Deposit', 'Rent in advance', 'Grocery Package'], array_column($lines, 'description'));
        $this->assertSame(124500, array_sum(array_column($lines, 'amount_cents')));
        $endpoint = $path.'/tenants/'.$tenant->id;
        $data = ['issue_date' => '2026-11-27', 'agreement_id' => $agreement->id, 'lines' => $lines, 'submission_key' => (string) Str::uuid()];
        $response = $this->postJson($endpoint.'/preview', $data)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertDatabaseCount('accommodation_rental_invoices', 0);

        $data['lines'][0]['amount_cents'] = 35000;
        $data['due_date'] = '2026-11-30';
        $this->post($endpoint, $data)->assertSessionHasNoErrors()->assertRedirect($path.'?tenant='.$tenant->id);
        $this->post($endpoint, $data)->assertSessionHasNoErrors();
        $invoice = AccommodationRentalInvoice::sole();
        $this->assertSame(123000, $invoice->details['total_cents']);
        $this->assertSame(15000, $invoice->details['package_subtotal']);
        $this->assertSame('2026-11-30', $invoice->details['due_date']);
        $this->assertSame('01-0288-0332767-00', $invoice->details['bank']['account']);
        $this->get($path.'?tenant='.$tenant->id)->assertInertia(fn ($page) => $page->has('history.data', 1)->has('agreements', 0));
        $this->get($path.'/'.$invoice->id.'/pdf')->assertDownload($invoice->reference.'.pdf');
        $this->postJson($endpoint, [...$data, 'submission_key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('agreement_id');
    }

    public function test_overdue_uses_captured_balances_and_preserves_weekend_receipt_and_friday_due_dates(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 10:00', 'Pacific/Auckland'));
        $tenant = $this->tenant();
        $this->actingAs(User::factory()->create(['role' => 'accommodation', 'email' => 'exalt-finance@example.test']));
        foreach (['2026-11-09', '2026-11-16', '2026-11-23'] as $week) {
            DB::table(WeeklyRentCharge::TABLE)->insert([
                'tenant_id' => $tenant->id, 'property_id' => $tenant->property_id, 'week_start' => $week, 'due_nzd' => '365.00',
            ]);
        }
        $payment = "/portal/accommodation/rent-utilities/tenants/{$tenant->id}/payment";
        $this->patch($payment, ['week_start' => '2026-11-09', 'received_on' => '2026-11-07', 'amount' => '200.00'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('2026-11-07', $tenant->rentPayments()->firstOrFail()->received_on->toDateString());
        $path = self::PATH.'/overdue';
        $props = fn () => $this->get($path.'?tenant='.$tenant->id)->assertOk()->inertiaProps();
        [$newer, $older] = $props()['weeks'];
        $this->assertSame('2026-11-06', $older['original_due']);
        $this->assertSame('2026-11-15', $older['end']);
        $this->assertSame(16500, $older['outstanding_cents']);
        $this->assertSame('2026-11-07', $older['received_on']);
        $this->assertSame('2026-11-16', $newer['start']);

        $pick = fn ($row) => ['start' => $row['start'], 'version' => $row['version'], 'amount_cents' => $row['outstanding_cents']];
        $data = ['submission_key' => (string) Str::uuid(), 'issue_date' => '2026-11-16',
            'payment_check_confirmed' => true, 'weeks' => [$pick($older), $pick($newer)]];
        $endpoint = $path.'/tenants/'.$tenant->id;
        $this->postJson($endpoint.'/preview', $data)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->postJson($endpoint, [...$data, 'payment_check_confirmed' => false])->assertUnprocessable()
            ->assertJsonValidationErrors('payment_check_confirmed');

        $this->patch($payment, ['week_start' => '2026-11-09', 'amount' => '210.00'])->assertSessionHasNoErrors();
        $this->postJson($endpoint, $data)->assertUnprocessable()->assertJsonValidationErrors('weeks');
        [$newer, $older] = $props()['weeks'];
        $data['weeks'] = [$pick($older), $pick($newer)];
        $this->post($endpoint, $data)->assertSessionHasNoErrors()->assertRedirect($path.'?tenant='.$tenant->id);
        $invoice = AccommodationRentalInvoice::sole();
        $this->assertSame(52000, $invoice->details['total_cents']);
        $this->assertSame('2026-11-18', $invoice->details['due_date']);
        $this->assertSame('2026-11-07', $invoice->details['weeks'][0]['received_on']);
        $this->assertCount(0, $props()['weeks']);
        $this->get($path.'/'.$invoice->id.'/pdf')->assertDownload($invoice->reference.'.pdf');
        $this->actingAs(User::factory()->create(['role' => 'accommodation']));
        $this->get($path.'?tenant='.$tenant->id)->assertForbidden();
        $this->get($path.'/'.$invoice->id.'/pdf')->assertForbidden();
    }

    private function tenant(): Tenant
    {
        $property = Property::create(['name' => 'Sample house', 'address' => '10 Sample Road', 'is_active' => true]);

        return Tenant::create(['property_id' => $property->id, 'first_name' => 'Sample', 'family_name' => 'Client',
            'current_status' => 'active', 'weekly_rent_nzd' => 365, 'contract_start' => '2026-10-05']);
    }
}
