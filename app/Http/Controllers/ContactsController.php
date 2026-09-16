<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Contacts/Index', [
            'filters' => Request::all('search', 'trashed'),
            'contacts' => Auth::user()->account->contacts()
                ->with('organization')
                ->orderByName()
                ->filter(Request::only('search', 'trashed'))
                ->paginate(10)
                ->withQueryString()
                ->through(fn ($contact) => [
                    'id' => $contact->id,
                    'name' => $contact->name,
                    'phone' => $contact->phone,
                    'city' => $contact->city,
                    'deleted_at' => $contact->deleted_at,
                    'organization' => $contact->organization ? $contact->organization->only('name') : null,
                ]),
        ]);
    }

    public function export(): StreamedResponse
    {
        $filters = Request::only('search', 'trashed');

        return response()->streamDownload(function () use ($filters) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'First Name', 'Last Name', 'Email', 'Phone', 'Address', 'City', 'Region', 'Country', 'Postal Code', 'Organization',
            ]);

            $cursor = null;

            // Cursor (keyset) pagination advances by comparing the actual sort-key
            // values of the last row returned, not a numeric offset, so concurrent
            // inserts/deletes elsewhere in the table can't shift a page boundary and
            // cause rows to be skipped or duplicated. The trailing orderBy('id') breaks
            // ties between contacts that share the same name, keeping the cursor unique.
            do {
                $page = Auth::user()->account->contacts()
                    ->with('organization')
                    ->orderByName()
                    ->orderBy('id')
                    ->filter($filters)
                    ->cursorPaginate(1000, ['*'], 'cursor', $cursor);

                foreach ($page as $contact) {
                    fputcsv($handle, array_map($this->csvField(...), [
                        $contact->first_name,
                        $contact->last_name,
                        $contact->email,
                        $contact->phone,
                        $contact->address,
                        $contact->city,
                        $contact->region,
                        $contact->country,
                        $contact->postal_code,
                        $contact->organization?->name ?? '',
                    ]));
                }

                $cursor = $page->nextCursor();
            } while ($cursor !== null);

            fclose($handle);
        }, 'contacts.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Neutralize spreadsheet formula injection (CWE-1236) by escaping fields that
     * start with a character a spreadsheet application would interpret as a formula.
     */
    private function csvField(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[\t\r=+\-@]/', $value) ? "'".$value : $value;
    }

    public function create(): Response
    {
        return Inertia::render('Contacts/Create', [
            'organizations' => Auth::user()->account
                ->organizations()
                ->orderBy('name')
                ->get()
                ->map
                ->only('id', 'name'),
        ]);
    }

    public function store(): RedirectResponse
    {
        Auth::user()->account->contacts()->create(
            Request::validate([
                'first_name' => ['required', 'max:50'],
                'last_name' => ['required', 'max:50'],
                'organization_id' => ['nullable', Rule::exists('organizations', 'id')->where(function ($query) {
                    $query->where('account_id', Auth::user()->account_id);
                })],
                'email' => ['nullable', 'max:50', 'email'],
                'phone' => ['nullable', 'max:50'],
                'address' => ['nullable', 'max:150'],
                'city' => ['nullable', 'max:50'],
                'region' => ['nullable', 'max:50'],
                'country' => ['nullable', 'max:2'],
                'postal_code' => ['nullable', 'max:25'],
            ])
        );

        return Redirect::route('contacts')->with('success', 'Contact created.');
    }

    public function edit(Contact $contact): Response
    {
        return Inertia::render('Contacts/Edit', [
            'contact' => [
                'id' => $contact->id,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                'organization_id' => $contact->organization_id,
                'email' => $contact->email,
                'phone' => $contact->phone,
                'address' => $contact->address,
                'city' => $contact->city,
                'region' => $contact->region,
                'country' => $contact->country,
                'postal_code' => $contact->postal_code,
                'deleted_at' => $contact->deleted_at,
            ],
            'organizations' => Auth::user()->account->organizations()
                ->orderBy('name')
                ->get()
                ->map
                ->only('id', 'name'),
        ]);
    }

    public function update(Contact $contact): RedirectResponse
    {
        $contact->update(
            Request::validate([
                'first_name' => ['required', 'max:50'],
                'last_name' => ['required', 'max:50'],
                'organization_id' => [
                    'nullable',
                    Rule::exists('organizations', 'id')->where(fn ($query) => $query->where('account_id', Auth::user()->account_id)),
                ],
                'email' => ['nullable', 'max:50', 'email'],
                'phone' => ['nullable', 'max:50'],
                'address' => ['nullable', 'max:150'],
                'city' => ['nullable', 'max:50'],
                'region' => ['nullable', 'max:50'],
                'country' => ['nullable', 'max:2'],
                'postal_code' => ['nullable', 'max:25'],
            ])
        );

        return Redirect::back()->with('success', 'Contact updated.');
    }

    public function destroy(Contact $contact): RedirectResponse
    {
        $contact->delete();

        return Redirect::back()->with('success', 'Contact deleted.');
    }

    public function restore(Contact $contact): RedirectResponse
    {
        $contact->restore();

        return Redirect::back()->with('success', 'Contact restored.');
    }
}
