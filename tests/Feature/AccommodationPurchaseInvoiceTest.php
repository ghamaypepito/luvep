<?php

namespace Tests\Feature;

use App\Models\AccommodationPurchaseInvoice;
use App\Models\MediaFolder;
use App\Models\MediaShare;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccommodationPurchaseInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $propertyName): Tenant
    {
        $property = Property::create(['name' => $propertyName, 'rent_single' => 200, 'status' => 'available']);

        return Tenant::create(['property_id' => $property->id, 'first_name' => 'Example', 'family_name' => 'Tenant']);
    }

    public function test_invoice_and_receipts_are_private_and_keep_the_original_property_history(): void
    {
        Storage::fake('local');
        $tenant = $this->tenant('Original property');
        $otherTenant = $this->tenant('New property');
        $staff = User::factory()->create(['role' => 'accommodation']);
        $existingFolder = MediaFolder::create(['tenant_id' => $tenant->id, 'client_key' => 'tenant:'.$tenant->id, 'name' => 'Tenant files']);
        $url = '/portal/accommodation/purchase-invoices';
        $store = '/portal/accommodation/tenants/'.$tenant->id.'/purchase-invoices';
        $payload = [
            'source' => 'upload',
            'submission_key' => (string) Str::uuid(), 'reference' => 'ex-test-1',
            'issue_date' => '2026-11-12', 'due_date' => '2026-11-13', 'total' => '391.61',
            'invoice' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf'),
            'receipts' => [UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf')],
        ];

        $this->actingAs($staff)->post($store, $payload)->assertSessionHasNoErrors()->assertRedirect($url.'?tenant='.$tenant->id);
        $this->post($store, $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->post($store, [...$payload, 'total' => '400.00'])->assertInvalid('reference');
        $this->assertDatabaseCount('accommodation_purchase_invoices', 1);
        $invoice = AccommodationPurchaseInvoice::sole();
        $this->assertNull($invoice->folder->parent_id);
        $this->assertFalse($existingFolder->subtree()->whereKey($invoice->folder_id)->exists());
        $this->assertCount(2, $invoice->folder->files);

        $tenant->update(['property_id' => $otherTenant->property_id]);
        $this->get($url.'?property='.$invoice->property_id)->assertInertia(fn (Assert $page) => $page
            ->has('invoices.data', 1)->where('invoices.data.0.reference', 'EX-TEST-1')
            ->where('invoices.data.0.property_address', 'Original property')->has('invoices.data.0.files', 2));
        $this->get($url.'?property='.$otherTenant->property_id)->assertInertia(fn (Assert $page) => $page->has('invoices.data', 0));
        $download = $url.'/'.$invoice->id.'/files/'.$invoice->invoice_file_id;
        $this->get($download)->assertDownload('invoice.pdf');
        $this->get($download.'?inline=1')->assertHeader('Content-Type', 'application/pdf');

        $shareUrl = '/media-drive/api/folders/'.$invoice->folder_id.'/shares';
        $recipient = ['email' => 'client@example.test', 'expires_in_days' => 30];
        $this->postJson($shareUrl, [...$recipient, 'permission' => 'editor'])->assertInvalid('permission');
        $share = $this->postJson($shareUrl, [...$recipient, 'permission' => 'viewer'])->assertCreated()->json('share');
        $sharedPath = parse_url($share['url'], PHP_URL_PATH);
        $this->get($sharedPath.'/files/'.$invoice->invoice_file_id)->assertForbidden();
        $version = MediaShare::findOrFail($share['id'])->version;
        $this->withSession(['media_grants.'.$share['id'] => ['version' => $version, 'expires' => now()->addHour()->timestamp]]);
        $this->getJson($sharedPath.'/contents')->assertOk()->assertJsonCount(2, 'files.data');
        $this->getJson($sharedPath.'/contents?folder_id='.$existingFolder->id)->assertNotFound();
        $this->get($sharedPath.'/files/'.$invoice->invoice_file_id)->assertDownload('invoice.pdf');

        $payload['submission_key'] = (string) Str::uuid();
        $this->post($store, $payload)->assertInvalid('reference');
        $this->assertCount(2, Storage::disk('local')->allFiles());
        $this->actingAs(User::factory()->create(['role' => 'sales', 'portal_access' => ['accommodation']]))
            ->get($download)->assertForbidden();
    }

    public function test_staff_generate_a_pdf_using_the_entered_number_and_gst_breakdown(): void
    {
        Storage::fake('local');
        $tenant = $this->tenant('Example property');
        $store = '/portal/accommodation/tenants/'.$tenant->id.'/purchase-invoices';
        $this->actingAs(User::factory()->create(['role' => 'accommodation']));
        $payload = [
            'source' => 'generate', 'submission_key' => (string) Str::uuid(), 'reference' => 'EX-TEST-2',
            'issue_date' => '2026-11-12', 'due_date' => '2026-11-13',
            'bill_to' => 'Example tenant', 'payment_instructions' => 'Use your invoice number as the payment reference.',
            'lines' => [['description' => 'Household purchases', 'amount' => '341.61'], ['description' => 'Service fee', 'amount' => '50.00']],
            'gst' => '6.52',
        ];
        $this->post($store, $payload)->assertInvalid('gst_number');
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->post($store, [...$payload, 'gst_number' => '000-000-000'])->assertSessionHasNoErrors()->assertRedirect();
        $invoice = AccommodationPurchaseInvoice::sole();
        $this->assertSame('391.61', $invoice->total);
        $file = $invoice->folder->files()->findOrFail($invoice->invoice_file_id);
        $pdf = (new \Smalot\PdfParser\Parser)->parseContent(Storage::disk('local')->get($file->file_path))->getText();
        foreach (['EX-TEST-2', 'Example property', 'Household purchases', 'Service fee', '391.61', '6.52'] as $value) {
            $this->assertStringContainsString($value, $pdf);
        }
        $this->assertDatabaseCount('finance_receivables', 0);
    }
}
