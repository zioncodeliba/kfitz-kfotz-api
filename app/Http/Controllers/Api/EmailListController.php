<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailList;
use App\Models\EmailListContact;
use App\Models\Merchant;
use App\Models\MerchantCustomer;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmailListController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $withContacts = $request->boolean('with_contacts');

        $lists = EmailList::query()
            ->withCount('contacts')
            ->when($withContacts, fn ($query) => $query->with('contacts'))
            ->orderBy('name')
            ->get();

        return $this->successResponse($lists);
    }

    public function show(EmailList $list)
    {
        $list->load(['contacts' => function ($query) {
            $query->orderBy('name')->orderBy('email');
        }]);

        return $this->successResponse($list);
    }

    public function store(Request $request)
    {
        $validated = $this->validateList($request);

        $list = EmailList::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        $contactsPayload = $validated['contacts'] ?? [];
        if (!empty($contactsPayload)) {
            $contacts = $this->buildContactsFromPayload($contactsPayload);
            $list->contacts()->createMany($contacts);
        }

        return $this->successResponse($list->fresh('contacts'), 'Mailing list created');
    }

    public function update(Request $request, EmailList $list)
    {
        $validated = $this->validateList($request, $list->id, false);

        $list->fill([
            'name' => $validated['name'] ?? $list->name,
            'description' => $validated['description'] ?? $list->description,
            'updated_by' => $request->user()?->id,
        ]);
        $list->save();

        if (!empty($validated['contacts'])) {
            $contacts = $this->buildContactsFromPayload($validated['contacts']);
            $this->persistContacts($list, $contacts);
        }

        return $this->successResponse($list->fresh('contacts'), 'Mailing list updated');
    }

    public function destroy(EmailList $list)
    {
        $list->delete();

        return $this->successResponse(null, 'Mailing list deleted');
    }

    public function addContacts(Request $request, EmailList $list)
    {
        $validated = $request->validate([
            'contacts' => 'required|array|min:1',
            'contacts.*.type' => ['required', Rule::in(['merchant', 'customer', 'manual'])],
            'contacts.*.reference_id' => 'nullable|string',
            'contacts.*.name' => 'nullable|string|max:255',
            'contacts.*.email' => 'nullable|email|max:255',
            'contacts.*.phone' => 'nullable|string|max:255',
        ]);

        $contacts = $this->buildContactsFromPayload($validated['contacts']);
        $this->persistContacts($list, $contacts);

        return $this->successResponse($list->fresh('contacts'), 'Contacts added');
    }

    public function removeContact(EmailList $list, EmailListContact $contact)
    {
        if ($contact->email_list_id !== $list->id) {
            return $this->errorResponse('Contact does not belong to this list', 422);
        }

        $contact->delete();

        return $this->successResponse(null, 'Contact removed');
    }

    protected function validateList(Request $request, ?int $listId = null, bool $requireName = true): array
    {
        return $request->validate([
            'name' => [
                $requireName ? 'required' : 'sometimes',
                'string',
                'max:255',
                Rule::unique('email_lists', 'name')->ignore($listId),
            ],
            'description' => 'nullable|string',
            'contacts' => 'nullable|array',
            'contacts.*.type' => ['required_with:contacts', Rule::in(['merchant', 'customer', 'manual'])],
            'contacts.*.reference_id' => 'nullable|string',
            'contacts.*.name' => 'nullable|string|max:255',
            'contacts.*.email' => 'nullable|email|max:255',
            'contacts.*.phone' => 'nullable|string|max:255',
        ]);
    }

    protected function buildContactsFromPayload(array $contacts): array
    {
        $normalized = [];
        $merchantIds = [];
        $customerIds = [];

        foreach ($contacts as $contact) {
            $type = $contact['type'];
            $referenceId = $contact['reference_id'] ?? null;

            if (in_array($type, ['merchant', 'customer'], true) && !$referenceId) {
                continue;
            }

            if ($type === 'merchant') {
                $merchantIds[] = $referenceId;
            } elseif ($type === 'customer') {
                $customerIds[] = $referenceId;
            } else {
                $normalized[] = [
                    'contact_type' => 'manual',
                    'reference_id' => null,
                    'name' => $contact['name'] ?? null,
                    'email' => $contact['email'] ?? null,
                    'phone' => $contact['phone'] ?? null,
                    'metadata' => null,
                ];
            }
        }

        $merchants = Merchant::query()
            ->with('user')
            ->whereIn('id', array_filter(array_unique($merchantIds)))
            ->get()
            ->keyBy('id');

        foreach ($merchants as $merchant) {
            $normalized[] = [
                'contact_type' => 'merchant',
                'reference_id' => (string) $merchant->id,
                'name' => $merchant->business_name ?? $merchant->contact_name ?? optional($merchant->user)->name ?? 'סוחר',
                'email' => $merchant->email_for_orders ?? optional($merchant->user)->email,
                'phone' => $merchant->phone ?? optional($merchant->user)->phone,
                'metadata' => [
                    'merchant_user_id' => $merchant->user_id,
                ],
            ];
        }

        $customers = MerchantCustomer::query()
            ->whereIn('id', array_filter(array_unique($customerIds)))
            ->get()
            ->keyBy('id');

        foreach ($customers as $customer) {
            $normalized[] = [
                'contact_type' => 'customer',
                'reference_id' => (string) $customer->id,
                'name' => $customer->name ?? 'לקוח',
                'email' => $customer->email,
                'phone' => $customer->phone,
                'metadata' => [
                    'merchant_user_id' => $customer->merchant_user_id,
                ],
            ];
        }

        return array_filter($normalized, function ($contact) {
            return $contact['email'] || $contact['phone'];
        });
    }

    protected function persistContacts(EmailList $list, array $contacts): void
    {
        foreach ($contacts as $contact) {
            $query = $list->contacts()->where('contact_type', $contact['contact_type']);
            if ($contact['reference_id']) {
                $query->where('reference_id', $contact['reference_id']);
            } elseif ($contact['email']) {
                $query->where('email', $contact['email']);
            }

            if ($query->exists()) {
                continue;
            }

            $list->contacts()->create($contact);
        }
    }
}
