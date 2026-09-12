<?php

namespace App\Http\Controllers\Api;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
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
use App\Models\Transaction;
use App\Services\SubscriptionService;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly TransactionService $transactionService
    ) {}

    /**
     * Display a listing of subscriptions for the active workspace.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->subscriptionService->processDueSubscriptions($request->workspace());

        $subscriptions = $request->workspace()
            ->subscriptions()
            ->with(['members.payments', 'creditCard', 'bankAccount', 'category', 'transactions'])
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

        $subscription->load(['members.payments', 'creditCard', 'bankAccount', 'category', 'transactions']);

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

        $subscription->load(['members.payments', 'creditCard', 'bankAccount', 'category', 'transactions']);

        return (new SubscriptionResource($subscription))->response();
    }

    /**
     * Update the specified subscription.
     */
    public function update(UpdateSubscriptionRequest $request, Subscription $subscription): JsonResponse
    {
        $this->ensureWorkspaceSubscription($request, $subscription);

        DB::transaction(function () use ($request, $subscription): void {
            $subscriptionData = $request->safe()->except(['members']);
            $subscription->update($subscriptionData);

            if ($request->has('members')) {
                $submittedMembers = $request->input('members') ?? [];
                $keptMemberIds = [];

                foreach ($submittedMembers as $memberData) {
                    if (! empty($memberData['id'])) {
                        $member = $subscription->members()->find($memberData['id']);
                        if ($member) {
                            $member->update([
                                'name' => $memberData['name'],
                                'installment_amount' => $memberData['installment_amount'],
                                'contact' => $memberData['contact'] ?? null,
                                'user_id' => $memberData['user_id'] ?? null,
                            ]);
                            $keptMemberIds[] = $member->id;

                            continue;
                        }
                    }

                    $newMember = $subscription->members()->create([
                        'name' => $memberData['name'],
                        'installment_amount' => $memberData['installment_amount'],
                        'contact' => $memberData['contact'] ?? null,
                        'user_id' => $memberData['user_id'] ?? null,
                    ]);
                    $keptMemberIds[] = $newMember->id;
                }

                $subscription->members()->whereNotIn('id', $keptMemberIds)->delete();
            }

            // If payment method, amount or service name changed, update any pending transaction in the current cycle
            $currentMonth = now()->format('Y-m');
            $pendingTransaction = $subscription->transactions()
                ->where('status', TransactionStatus::Pending)
                ->where('occurred_at', 'like', "{$currentMonth}%")
                ->latest('occurred_at')
                ->first();

            if ($pendingTransaction) {
                $updateData = [];
                if ($request->has('credit_card_id')) {
                    $updateData['credit_card_id'] = $subscription->credit_card_id;
                }
                if ($request->has('bank_account_id')) {
                    $updateData['bank_account_id'] = $subscription->bank_account_id;
                }
                if ($request->has('total_amount')) {
                    $updateData['amount'] = $subscription->total_amount;
                }
                if ($request->has('service_name')) {
                    $updateData['description'] = 'Assinatura: '.$subscription->service_name;
                }
                if (! empty($updateData)) {
                    $this->transactionService->update($pendingTransaction, $updateData);
                }
            }
        });

        $subscription->load(['members.payments', 'creditCard', 'bankAccount', 'category', 'transactions']);

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
     * If marked as paid, credits the primary bank account. If marked as pending, reverts the credit.
     */
    public function recordPayment(RecordMemberPaymentRequest $request, Subscription $subscription, SubscriptionMember $member): JsonResponse
    {
        $this->ensureWorkspaceSubscription($request, $subscription);

        if ($member->subscription_id !== $subscription->id) {
            abort(404, 'Member does not belong to this subscription.');
        }

        $referenceMonth = $request->input('reference_month');
        $status = $request->input('status');
        $amount = (float) $request->input('amount', $member->installment_amount);
        $paymentDate = $request->input('payment_date', ($status === 'paid' ? now()->toDateString() : null));

        $payment = $this->subscriptionService->recordMemberPayment(
            member: $member,
            referenceMonth: $referenceMonth,
            status: $status,
            amount: $amount,
            paymentDate: $paymentDate,
            userId: $request->user()?->id
        );

        return (new SubscriptionPaymentResource($payment))->response();
    }

    /**
     * Mark an individual subscription as paid for the cycle and apply financial impact.
     */
    public function pay(Request $request, Subscription $subscription): JsonResponse
    {
        $this->ensureWorkspaceSubscription($request, $subscription);

        $amount = (float) $request->input('amount', $subscription->total_amount);
        $paymentDate = $request->input('payment_date', now()->toDateString());
        $referenceMonth = substr($paymentDate, 0, 7);
        $bankAccountId = $request->input('bank_account_id', $subscription->bank_account_id);
        $creditCardId = $request->input('credit_card_id', $subscription->credit_card_id);

        if (! $bankAccountId && ! $creditCardId) {
            $primaryAccount = $subscription->workspace->getPrimaryBankAccount();
            if ($primaryAccount) {
                $bankAccountId = $primaryAccount->id;
            }
        }

        $existing = $subscription->transactions()
            ->where('occurred_at', 'like', "{$referenceMonth}%")
            ->latest('occurred_at')
            ->first();

        if ($existing) {
            $transaction = $this->transactionService->update($existing, [
                'amount' => $amount,
                'occurred_at' => $paymentDate,
                'bank_account_id' => $bankAccountId,
                'credit_card_id' => $creditCardId,
                'status' => TransactionStatus::Paid,
            ]);
        } else {
            $transaction = $this->transactionService->create([
                'workspace_id' => $subscription->workspace_id,
                'created_by_user_id' => $request->user()?->id,
                'subscription_id' => $subscription->id,
                'category_id' => $subscription->category_id,
                'type' => TransactionType::Expense,
                'amount' => $amount,
                'occurred_at' => $paymentDate,
                'status' => TransactionStatus::Paid,
                'description' => 'Assinatura: '.$subscription->service_name,
                'bank_account_id' => $bankAccountId,
                'credit_card_id' => $creditCardId,
                'notes' => "Pagamento assinatura ciclo {$referenceMonth}",
            ]);
        }

        $subscription->load(['members.payments', 'creditCard', 'bankAccount', 'category', 'transactions']);

        return response()->json([
            'message' => 'Subscription paid successfully.',
            'subscription' => new SubscriptionResource($subscription),
        ]);
    }

    /**
     * Mark an individual subscription as unpaid for the cycle and revert balance.
     */
    public function unpay(Request $request, Subscription $subscription): JsonResponse
    {
        $this->ensureWorkspaceSubscription($request, $subscription);

        $referenceMonth = $request->input('reference_month', now()->format('Y-m'));

        $transaction = $subscription->transactions()
            ->where('occurred_at', 'like', "{$referenceMonth}%")
            ->latest('occurred_at')
            ->first();

        if ($transaction) {
            if ($transaction->credit_card_id && $subscription->credit_card_id) {
                $this->transactionService->update($transaction, [
                    'status' => TransactionStatus::Pending,
                ]);
            } else {
                $this->transactionService->delete($transaction);
            }
        }

        $subscription->load(['members.payments', 'creditCard', 'bankAccount', 'category', 'transactions']);

        return response()->json([
            'message' => 'Subscription marked as unpaid.',
            'subscription' => new SubscriptionResource($subscription),
        ]);
    }

    private function ensureWorkspaceSubscription(Request $request, Subscription $subscription): void
    {
        if ($subscription->workspace_id !== $request->workspace()->id) {
            abort(404, 'Subscription not found.');
        }
    }
}
