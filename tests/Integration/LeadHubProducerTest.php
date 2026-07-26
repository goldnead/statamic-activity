<?php

use Goldnead\Activity\Models\Activity as ActivityModel;
use Goldnead\Activity\Producers\LeadHubProducer;
use Goldnead\IdentityContracts\Identity;
use Goldnead\Leadhub\Events\LeadHubContactCreated;
use Goldnead\Leadhub\Events\LeadHubStatusChanged;
use Goldnead\Leadhub\Models\Contact;

beforeEach(function (): void {
    LeadHubProducer::register(app('activity')->producers());

    $this->contact = Contact::create([
        'uuid' => (string) Str::uuid(),
        'email' => 'a@example.com',
        'email_normalized' => 'a@example.com',
        'status' => 'lead',
    ]);
});

it('records a contact lifecycle event against the contact uuid', function (): void {
    LeadHubContactCreated::dispatch($this->contact);

    $activity = ActivityModel::first();

    expect(ActivityModel::count())->toBe(1)
        ->and($activity->event_type)->toBe('crm.contact_created')
        ->and($activity->contact_uuid)->toBe($this->contact->uuid)
        ->and($activity->subject_type)->toBe(Contact::class);
});

it('attributes the record to the operator, not to the contact', function (): void {
    LeadHubStatusChanged::dispatch($this->contact, ['id' => 9, 'email' => 'operator@example.com', 'name' => 'Op']);

    $activity = ActivityModel::first();

    expect($activity->actor_type)->toBe(Identity::TYPE_USER)
        ->and($activity->user_id)->toBe('9')
        // …while the record still points at the contact it is about.
        ->and($activity->contact_uuid)->toBe($this->contact->uuid);
});

it('falls back to the system actor when nobody triggered it', function (): void {
    LeadHubStatusChanged::dispatch($this->contact);

    expect(ActivityModel::first()->actor_type)->toBe(Identity::TYPE_SYSTEM);
});

it('dedupes when leadhub supplies its own dedupe key', function (): void {
    LeadHubStatusChanged::dispatch($this->contact, null, ['dedupe_key' => 'status:1:won']);
    LeadHubStatusChanged::dispatch($this->contact, null, ['dedupe_key' => 'status:1:won']);

    expect(ActivityModel::count())->toBe(1);
});

it('leaves the leadhub timeline untouched', function (): void {
    // The two ledgers are allowed to overlap; what must not happen is the
    // activity producer writing into LeadHub's own table.
    $before = DB::table('leadhub_events')->count();

    LeadHubContactCreated::dispatch($this->contact);

    expect(DB::table('leadhub_events')->count())->toBe($before)
        ->and(ActivityModel::count())->toBe(1);
});
