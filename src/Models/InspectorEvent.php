<?php

namespace FelicianoPJ\CashierInspector\Models;

use FelicianoPJ\CashierInspector\Support\BillableResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InspectorEvent extends Model
{
    protected $table = 'cashier_inspector_events';

    protected $fillable = [
        'stripe_event_id',
        'stripe_event_type',
        'stripe_api_version',
        'livemode',
        'payload',
        'customer_id',
        'subscription_id',
        'invoice_id',
        'checkout_session_id',
        'billable_type',
        'billable_id',
    ];

    protected $casts = [
        'livemode' => 'boolean',
        'payload' => 'array',
    ];

    public function deliveries(): HasMany
    {
        return $this->hasMany(InspectorDelivery::class, 'event_id');
    }

    public function diagnostics(): HasMany
    {
        return $this->hasMany(InspectorDiagnostic::class, 'event_id');
    }

    public function getRouteKeyName(): string
    {
        return 'stripe_event_id';
    }

    /**
     * Matches the identifiers a developer would typically paste in
     * (evt_/cus_/sub_/invoice/checkout id, or a local billable model id
     * now that BillableResolver populates billable_id/billable_type),
     * plus the local billable email.
     *
     * Email is deliberately not stored on this table. The term is resolved
     * to billable model ids against the application's own customer table
     * first, then matched here by id, so redaction never has to make an
     * exception for it. See BillableResolver::idsMatchingEmail() for what
     * that lookup can and cannot find.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $billable = (new BillableResolver)->idsMatchingEmail($term);

        return $query->where(function (Builder $query) use ($term, $billable) {
            $query->where('stripe_event_id', 'like', "%{$term}%")
                ->orWhere('customer_id', 'like', "%{$term}%")
                ->orWhere('subscription_id', 'like', "%{$term}%")
                ->orWhere('invoice_id', 'like', "%{$term}%")
                ->orWhere('checkout_session_id', 'like', "%{$term}%")
                ->orWhere('billable_id', 'like', "%{$term}%");

            if ($billable) {
                $query->orWhere(fn (Builder $query) => $query
                    ->where('billable_type', $billable['billable_type'])
                    ->whereIn('billable_id', $billable['billable_ids']));
            }
        });
    }
}
