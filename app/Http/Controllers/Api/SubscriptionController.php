<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AddSubscriptionMemberRequest;
use App\Http\Requests\Api\RecordMemberPaymentRequest;
use App\Http\Requests\Api\StoreSubscriptionRequest;
use App\Http\Requests\Api\UpdateSubscriptionRequest;
use App\Http\Resources\SubscriptionMemberResource;
use App\Http\Resources\SubscriptionPaymentResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Subscription;
use App\Models\SubscriptionMember;
use App\Models\SubscriptionPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class SubscriptionController extends Controller
{
    /**
     * Display a listing of subscriptions for the active workspace.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $subscriptions = $request->workspace()
            ->subscriptions()
            ->with(['members.payments', 'creditCard', 'bankAccount', 'category'])
            ->orderBy('billing_day')
            ->get();

        return SubscriptionResource::collection($subscriptions);
    }

    /**
     * Store a newly created subscription (with optional initial members).
     */
    public function store(StoreSubscriptionRequest $request): JsonResponse
    {
        $workspace = $request->workspace();

        $subscription = DB::transaction(function () use ($request, $workspace): Subscription {
            $subscriptionData = $request->safe()->except(['members']);
            $subscriptionData['workspace_id'] = $workspace->id;

            $subscription = Subscription::create($subscriptionData);

            if ($request->has('members')) {
                foreach ($request->input('members') as $memberData) {
                    $subscription->members()->create($memberData);
                }
            }

            return $subscription;
        });

        $subscription->load(['members.payments', 'creditCard', 'bankAccount', 'category']);

        return (new SubscriptionResource($subscription))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified subscription.
     */
    public function show(Request $request, Subscription $subscription): JsonResponse
    {
        $this->ensureWorkspaceSubscription($request, $subscription);

        $subscription->load(['members.payments', 'creditCard', 'bankAccount', 'category']);

        return (new SubscriptionResource($subscription))->response();
    }

    /**
     * Update the specified subscription.
     */
    public function update(UpdateSubscriptionRequest $request, Subscription $subscription): JsonResponse
    {
        $this->ensureWorkspaceSubscription($request, $subscription);

        $subscription->update($request->validated());
        $subscription->load(['members.payments', 'creditCard', 'bankAccount', 'category']);

        return (new SubscriptionResource($subscription))->response();
    }

    /**
     * Remove the specified subscription.
     */
    public function destroy(Request $request, Subscription $subscription): JsonResponse
    {
        $this->ensureWorkspaceSubscription($request, $subscription);

        $subscription->delete();

        return response()->json([
            'message' => 'Subscription deleted successfully.',
        ]);
    }

    /**
     * Add a member to split this subscription.
     */
    public function addMember(AddSubscriptionMemberRequest $request, Subscription $subscription): JsonResponse
    {
        $this->ensureWorkspaceSubscription($request, $subscription);

        $member = $subscription->members()->create($request->validated());

        return (new SubscriptionMemberResource($member))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Remove a member from this subscription.
     */
    public function removeMember(Request $request, Subscription $subscription, SubscriptionMember $member): JsonResponse
    {
        $this->ensureWorkspaceSubscription($request, $subscription);

        if ($member->subscription_id !== $subscription->id) {
            abort(404, 'Member not found in this subscription.');
        }

        $member->delete();

        return response()->json([
            'message' => 'Member removed successfully.',
        ]);
    }

    /**
     * Record or update payment status for a member in a specific billing cycle (e.g. 2026-09).
     */
    public function recordPayment(RecordMemberPaymentRequest $request, Subscription $subscription, SubscriptionMember $member): JsonResponse
    {
        $this->ensureWorkspaceSubscription($request, $subscription);

        if ($member->subscription_id !== $subscription->id) {
            abort(404, 'Member does not belong to this subscription.');
        }

        $referenceMonth = $request->input('reference_month');
        $status = $request->input('status');
        $amount = $request->input('amount', $member->installment_amount);
        $paymentDate = $request->input('payment_date', ($status === 'paid' ? now()->toDateString() : null));

        $payment = SubscriptionPayment::updateOrCreate(
            [
                'subscription_member_id' => $member->id,
                'reference_month' => $referenceMonth,
            ],
            [
                'amount' => $amount,
                'status' => $status,
                'payment_date' => $paymentDate,
            ]
        );

        return (new SubscriptionPaymentResource($payment))->response();
    }

    private function ensureWorkspaceSubscription(Request $request, Subscription $subscription): void
    {
        if ($subscription->workspace_id !== $request->workspace()->id) {
            abort(404, 'Subscription not found.');
        }
    }
}
