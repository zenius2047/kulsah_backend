<?php

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Http\Controllers\Controller;
use App\Http\Resources\WalletLedgerEntryResource;
use App\Http\Resources\WalletResource;
use App\Http\Resources\WalletTransactionResource;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(private readonly WalletService $walletService)
    {
    }

    public function show(Request $request)
    {
        $wallet = $this->walletService->getOrCreateUserWallet($request->user());

        $wallet->load('user:id,name,username,avatar,banner');

        return response()->json([
            'data' => new WalletResource($wallet),
        ]);
    }

    public function transactions(Request $request)
    {
        $wallet = $this->walletService->getOrCreateUserWallet($request->user());

        $transactions = $wallet->transactions()
            ->with(['entries.wallet', 'wallet', 'counterpartyWallet'])
            ->latest()
            ->paginate(20);

        return response()->json([
            'data' => WalletTransactionResource::collection($transactions),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }

    public function ledger(Request $request)
    {
        $wallet = $this->walletService->getOrCreateUserWallet($request->user());

        $entries = $wallet->ledgerEntries()
            ->with(['wallet', 'walletTransaction'])
            ->latest()
            ->paginate(50);

        return response()->json([
            'data' => WalletLedgerEntryResource::collection($entries),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ],
        ]);
    }

    public function transfer(Request $request)
    {
        $validated = $request->validate([
            'recipient_id' => ['required', 'integer', 'exists:users,id'],
            'amount' => ['required_without:amount_usd', 'numeric', 'min:0.0001'],
            'amount_usd' => ['sometimes', 'numeric', 'min:0.0001'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $recipient = User::query()->findOrFail($validated['recipient_id']);

        abort_if((string) $recipient->id === (string) $request->user()->id, 422, 'You cannot transfer funds to yourself.');

        $transaction = $this->walletService->transferBetweenWallets(
            fromWallet: $this->walletService->getOrCreateUserWallet($request->user()),
            toWallet: $this->walletService->getOrCreateUserWallet($recipient),
            amountUsd: $validated['amount_usd'],
            type: 'wallet_transfer',
            description: $validated['description'] ?? 'Wallet transfer',
            metadata: [
                'recipient_id' => $recipient->id,
            ],
            fromBucket: 'available',
            toBucket: 'available',
            actor: $request->user()
        );

        return response()->json([
            'message' => 'Wallet transfer completed successfully.',
            'data' => new WalletTransactionResource($transaction),
        ], 201);
    }

    public function topUp(Request $request)
    {
        $validated = $request->validate([
            'amount' => ['required_without:amount_usd', 'numeric', 'min:0.0001'],
            'amount_usd' => ['sometimes', 'numeric', 'min:0.0001'],
            'description' => ['nullable', 'string', 'max:500'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
        ]);

        $transaction = $this->walletService->transferBetweenWallets(
            fromWallet: $this->walletService->getOrCreateSystemWallet('topup_gateway', 'Kulsah Top-up Gateway'),
            toWallet: $this->walletService->getOrCreateUserWallet($request->user()),
            amountUsd: $validated['amount_usd'],
            type: 'wallet_top_up',
            description: $validated['description'] ?? 'Wallet top-up',
            metadata: [
                'user_id' => $request->user()->id,
                'payment_reference' => $validated['payment_reference'] ?? null,
                'initiated_by' => $request->user()->id,
            ],
            fromBucket: 'available',
            toBucket: 'available',
            actor: $request->user(),
            allowNegativeSourceBalance: true
        );

        return response()->json([
            'message' => 'Wallet topped up successfully.',
            'data' => new WalletTransactionResource($transaction),
        ], 201);
    }
}
