<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AdjustBalanceRequest;
use App\Http\Requests\Api\StoreBankAccountRequest;
use App\Http\Requests\Api\UpdateBankAccountRequest;
use App\Http\Resources\BankAccountResource;
use App\Models\BankAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class BankAccountController extends Controller
{
    /**
     * Display a listing of bank accounts for the active workspace.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $accounts = $request->workspace()
            ->bankAccounts()
            ->with('user')
            ->where(function ($query) use ($user): void {
                $query->where('is_shared', true)
                    ->orWhere('user_id', $user->id)
                    ->orWhereNull('user_id');
            })
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get();

        return BankAccountResource::collection($accounts);
    }

    /**
     * Store a newly created bank account.
     */
    public function store(StoreBankAccountRequest $request): JsonResponse
    {
        $workspace = $request->workspace();
        $isFirst = ! $workspace->bankAccounts()->exists();
        $isPrimary = $request->boolean('is_primary') || $isFirst;

        $account = DB::transaction(function () use ($workspace, $request, $isPrimary): BankAccount {
            if ($isPrimary) {
                $workspace->bankAccounts()->update(['is_primary' => false]);
            }

            $data = $request->validated();
            $data['is_primary'] = $isPrimary;
            $data['user_id'] = $request->input('user_id') ?? $request->user()->id;
            $data['is_shared'] = $request->boolean('is_shared', true);

            return $workspace->bankAccounts()->create($data);
        });

        $account->load('user');

        return (new BankAccountResource($account))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified bank account.
     */
    public function show(Request $request, BankAccount $bankAccount): JsonResponse
    {
        $this->ensureWorkspaceAccount($request, $bankAccount);

        $bankAccount->load('user');

        return (new BankAccountResource($bankAccount))->response();
    }

    /**
     * Update the specified bank account.
     */
    public function update(UpdateBankAccountRequest $request, BankAccount $bankAccount): JsonResponse
    {
        $this->ensureWorkspaceAccount($request, $bankAccount);

        DB::transaction(function () use ($request, $bankAccount): void {
            if ($request->has('is_primary') && $request->boolean('is_primary')) {
                $request->workspace()->bankAccounts()->where('id', '!=', $bankAccount->id)->update(['is_primary' => false]);
            }

            $data = $request->validated();
            if ($request->has('is_shared')) {
                $data['is_shared'] = $request->boolean('is_shared');
            }

            $bankAccount->update($data);
        });

        return (new BankAccountResource($bankAccount->fresh(['user'])))->response();
    }

    /**
     * Remove the specified bank account.
     */
    public function destroy(Request $request, BankAccount $bankAccount): JsonResponse
    {
        $this->ensureWorkspaceAccount($request, $bankAccount);

        $wasPrimary = (bool) $bankAccount->is_primary;
        $workspace = $request->workspace();

        DB::transaction(function () use ($bankAccount, $wasPrimary, $workspace): void {
            $bankAccount->delete();

            if ($wasPrimary) {
                $nextAccount = $workspace->bankAccounts()->first();
                if ($nextAccount) {
                    $nextAccount->update(['is_primary' => true]);
                }
            }
        });

        return response()->json([
            'message' => 'Bank account deleted successfully.',
        ]);
    }

    /**
     * Set the specified bank account as the primary account for the workspace.
     */
    public function setPrimary(Request $request, BankAccount $bankAccount): JsonResponse
    {
        $this->ensureWorkspaceAccount($request, $bankAccount);

        DB::transaction(function () use ($request, $bankAccount): void {
            $request->workspace()->bankAccounts()->update(['is_primary' => false]);
            $bankAccount->update(['is_primary' => true]);
        });

        return (new BankAccountResource($bankAccount->fresh()))->response();
    }

    /**
     * Manually adjust the current balance of the bank account.
     */
    public function adjustBalance(AdjustBalanceRequest $request, BankAccount $bankAccount): JsonResponse
    {
        $this->ensureWorkspaceAccount($request, $bankAccount);

        $bankAccount->update([
            'current_balance' => $request->input('current_balance'),
        ]);

        return (new BankAccountResource($bankAccount))->response();
    }

    private function ensureWorkspaceAccount(Request $request, BankAccount $bankAccount): void
    {
        if ($bankAccount->workspace_id !== $request->workspace()->id) {
            abort(404, 'Bank account not found.');
        }
    }
}
