<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ContactsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'account_id' => Account::create(['name' => 'Acme Corporation'])->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'johndoe@example.com',
            'owner' => true,
        ]);

        $organization = $this->user->account->organizations()->create(['name' => 'Example Organization Inc.']);

        $this->user->account->contacts()->createMany([
            [
                'organization_id' => $organization->id,
                'first_name' => 'Martin',
                'last_name' => 'Abbott',
                'email' => 'martin.abbott@example.com',
                'phone' => '555-111-2222',
                'address' => '330 Glenda Shore',
                'city' => 'Murphyland',
                'region' => 'Tennessee',
                'country' => 'US',
                'postal_code' => '57851',
            ], [
                'organization_id' => $organization->id,
                'first_name' => 'Lynn',
                'last_name' => 'Kub',
                'email' => 'lynn.kub@example.com',
                'phone' => '555-333-4444',
                'address' => '199 Connelly Turnpike',
                'city' => 'Woodstock',
                'region' => 'Colorado',
                'country' => 'US',
                'postal_code' => '11623',
            ],
        ]);
    }

    public function test_can_view_contacts(): void
    {
        $this->actingAs($this->user)
            ->get('/contacts')
            ->assertInertia(fn (Assert $assert) => $assert
                ->component('Contacts/Index')
                ->has('contacts.data', 2)
                ->has('contacts.data.0', fn (Assert $assert) => $assert
                    ->has('id')
                    ->where('name', 'Martin Abbott')
                    ->where('phone', '555-111-2222')
                    ->where('city', 'Murphyland')
                    ->where('deleted_at', null)
                    ->has('organization', fn (Assert $assert) => $assert
                        ->where('name', 'Example Organization Inc.')
                    )
                )
                ->has('contacts.data.1', fn (Assert $assert) => $assert
                    ->has('id')
                    ->where('name', 'Lynn Kub')
                    ->where('phone', '555-333-4444')
                    ->where('city', 'Woodstock')
                    ->where('deleted_at', null)
                    ->has('organization', fn (Assert $assert) => $assert
                        ->where('name', 'Example Organization Inc.')
                    )
                )
            );
    }

    public function test_can_search_for_contacts(): void
    {
        $this->actingAs($this->user)
            ->get('/contacts?search=Martin')
            ->assertInertia(fn (Assert $assert) => $assert
                ->component('Contacts/Index')
                ->where('filters.search', 'Martin')
                ->has('contacts.data', 1)
                ->has('contacts.data.0', fn (Assert $assert) => $assert
                    ->has('id')
                    ->where('name', 'Martin Abbott')
                    ->where('phone', '555-111-2222')
                    ->where('city', 'Murphyland')
                    ->where('deleted_at', null)
                    ->has('organization', fn (Assert $assert) => $assert
                        ->where('name', 'Example Organization Inc.')
                    )
                )
            );
    }

    public function test_cannot_view_deleted_contacts(): void
    {
        $this->user->account->contacts()->firstWhere('first_name', 'Martin')->delete();

        $this->actingAs($this->user)
            ->get('/contacts')
            ->assertInertia(fn (Assert $assert) => $assert
                ->component('Contacts/Index')
                ->has('contacts.data', 1)
                ->where('contacts.data.0.name', 'Lynn Kub')
            );
    }

    public function test_can_filter_to_view_deleted_contacts(): void
    {
        $this->user->account->contacts()->firstWhere('first_name', 'Martin')->delete();

        $this->actingAs($this->user)
            ->get('/contacts?trashed=with')
            ->assertInertia(fn (Assert $assert) => $assert
                ->component('Contacts/Index')
                ->has('contacts.data', 2)
                ->where('contacts.data.0.name', 'Martin Abbott')
                ->where('contacts.data.1.name', 'Lynn Kub')
            );
    }

    public function test_guests_cannot_export_contacts(): void
    {
        $this->get('/contacts/export')
            ->assertRedirect('/login');
    }

    public function test_can_export_contacts_as_csv(): void
    {
        $response = $this->actingAs($this->user)->get('/contacts/export');

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('content-type'));

        $disposition = $response->headers->get('content-disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('.csv', $disposition);
    }

    public function test_exported_csv_contains_expected_contact_rows(): void
    {
        $response = $this->actingAs($this->user)->get('/contacts/export');

        $rows = $this->csvRows($response);

        $this->assertSame([
            'First Name', 'Last Name', 'Email', 'Phone', 'Address', 'City', 'Region', 'Country', 'Postal Code', 'Organization',
        ], $rows[0]);

        $this->assertCount(3, $rows);
        $this->assertSame([
            'Martin', 'Abbott', 'martin.abbott@example.com', '555-111-2222', '330 Glenda Shore', 'Murphyland', 'Tennessee', 'US', '57851', 'Example Organization Inc.',
        ], $rows[1]);
        $this->assertSame([
            'Lynn', 'Kub', 'lynn.kub@example.com', '555-333-4444', '199 Connelly Turnpike', 'Woodstock', 'Colorado', 'US', '11623', 'Example Organization Inc.',
        ], $rows[2]);
    }

    public function test_export_respects_search_filter(): void
    {
        $response = $this->actingAs($this->user)->get('/contacts/export?search=Martin');

        $rows = $this->csvRows($response);

        $this->assertCount(2, $rows);
        $this->assertSame('Martin', $rows[1][0]);
    }

    public function test_export_search_matches_email(): void
    {
        $rows = $this->csvRows($this->actingAs($this->user)->get('/contacts/export?search=lynn.kub@example.com'));

        $this->assertCount(2, $rows);
        $this->assertSame('Lynn', $rows[1][0]);
    }

    public function test_export_search_matches_organization_name(): void
    {
        $otherOrganization = $this->user->account->organizations()->create(['name' => 'Globex Corporation']);
        $this->user->account->contacts()->create([
            'organization_id' => $otherOrganization->id,
            'first_name' => 'Hank',
            'last_name' => 'Scorpio',
            'email' => 'hank.scorpio@example.com',
        ]);

        $rows = $this->csvRows($this->actingAs($this->user)->get('/contacts/export?search=Globex'));

        $this->assertCount(2, $rows);
        $this->assertSame('Hank', $rows[1][0]);
    }

    public function test_export_excludes_trashed_contacts_by_default(): void
    {
        $this->user->account->contacts()->firstWhere('first_name', 'Martin')->delete();

        $rows = $this->csvRows($this->actingAs($this->user)->get('/contacts/export'));

        $this->assertCount(2, $rows);
        $this->assertSame('Lynn', $rows[1][0]);
    }

    public function test_export_includes_trashed_contacts_when_filtered(): void
    {
        $this->user->account->contacts()->firstWhere('first_name', 'Martin')->delete();

        $rows = $this->csvRows($this->actingAs($this->user)->get('/contacts/export?trashed=only'));

        $this->assertCount(2, $rows);
        $this->assertSame('Martin', $rows[1][0]);
    }

    public function test_export_includes_all_contacts_with_trashed_filter(): void
    {
        $this->user->account->contacts()->firstWhere('first_name', 'Martin')->delete();

        $rows = $this->csvRows($this->actingAs($this->user)->get('/contacts/export?trashed=with'));

        $this->assertCount(3, $rows);
        $this->assertSame(['Martin', 'Lynn'], [$rows[1][0], $rows[2][0]]);
    }

    public function test_export_does_not_include_contacts_from_other_accounts(): void
    {
        $otherAccount = Account::create(['name' => 'Other Company Inc.']);
        $otherAccount->contacts()->create([
            'first_name' => 'Other',
            'last_name' => 'Account',
            'email' => 'other.account@example.com',
        ]);

        $rows = $this->csvRows($this->actingAs($this->user)->get('/contacts/export'));

        $this->assertCount(3, $rows);
        $this->assertSame(['Martin', 'Lynn'], [$rows[1][0], $rows[2][0]]);
    }

    public function test_export_includes_all_matching_rows_beyond_pagination_limit(): void
    {
        Contact::factory()->count(15)->create(['account_id' => $this->user->account_id]);

        $rows = $this->csvRows($this->actingAs($this->user)->get('/contacts/export'));

        // header + 2 originally seeded contacts + 15 factory-created contacts
        $this->assertCount(18, $rows);
    }

    public function test_export_escapes_formula_injection_in_fields(): void
    {
        $this->user->account->contacts()->create([
            'first_name' => '=cmd|\'/c calc\'!A1',
            'last_name' => '+Danger',
            'email' => 'formula@example.com',
        ]);

        $rows = $this->csvRows($this->actingAs($this->user)->get('/contacts/export?search=formula'));

        $this->assertCount(2, $rows);
        $this->assertStringStartsWith("'=", $rows[1][0]);
        $this->assertStringStartsWith("'+", $rows[1][1]);
    }

    public function test_export_correctly_escapes_fields_with_commas_and_quotes(): void
    {
        $this->user->account->contacts()->create([
            'first_name' => 'Comma',
            'last_name' => 'Test',
            'email' => 'comma.test@example.com',
            'address' => '123 Main St, Apt "4"',
        ]);

        $rows = $this->csvRows($this->actingAs($this->user)->get('/contacts/export?search=Comma'));

        $this->assertCount(2, $rows);
        $this->assertSame('123 Main St, Apt "4"', $rows[1][4]);
    }

    private function csvRows($response): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $response->streamedContent());
        rewind($stream);

        $rows = [];

        while (($row = fgetcsv($stream)) !== false) {
            $rows[] = $row;
        }

        fclose($stream);

        return $rows;
    }
}
