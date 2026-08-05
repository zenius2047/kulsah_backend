<?php

namespace App\Http\Controllers\Api\V1\KulCoin;

use App\Http\Controllers\Controller;
use App\Http\Resources\KulCoinGiftResource;
use App\Http\Resources\KulCoinLedgerEntryResource;
use App\Http\Resources\KulCoinPackageResource;
use App\Http\Resources\KulCoinTransactionResource;
use App\Http\Resources\KulCoinWalletResource;
use App\Models\KulCoinGift;
use App\Models\KulCoinPackage;
use App\Models\KulCoinTransaction;
use App\Models\User;
use App\Services\KulCoinService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class KulCoinController extends Controller
{
    public function __construct(private readonly KulCoinService $kulCoinService)
    {
    }

    public function wallet(Request $request)
    {
        $wallet = $this->kulCoinService->getOrCreateUserWallet($request->user());

        return response()->json([
            'data' => new KulCoinWalletResource($wallet),
        ]);
    }

    public function ledger(Request $request)
    {
        $wallet = $this->kulCoinService->getOrCreateUserWallet($request->user());

        $entries = $wallet->ledgerEntries()
            ->with(['wallet', 'kulCoinTransaction'])
            ->latest()
            ->paginate(50);

        return response()->json([
            'data' => KulCoinLedgerEntryResource::collection($entries),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ],
        ]);
    }

    public function packages()
    {
        return response()->json([
            'data' => KulCoinPackageResource::collection($this->kulCoinService->listPackages()),
        ]);
    }

    public function gifts()
    {
        return response()->json([
            'data' => KulCoinGiftResource::collection($this->kulCoinService->listGifts()),
        ]);
    }

    public function purchase(Request $request)
    {
        $validated = $request->validate([
            'package_id' => ['required', 'integer', 'exists:kulcoin_packages,id'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'local_currency' => ['nullable', 'string', 'max:10'],
            'local_amount' => ['nullable', 'numeric', 'min:0.0001'],
            'usd_amount' => ['nullable', 'numeric', 'min:0.0001'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
            'device_info' => ['nullable', 'array'],
        ]);

        try {
            $package = KulCoinPackage::query()->findOrFail($validated['package_id']);

            $transaction = $this->kulCoinService->purchasePackage(
                buyer: $request->user(),
                package: $package,
                data: array_merge($validated, [
                    'ip_address' => $request->ip(),
                    'device_info' => $validated['device_info'] ?? null,
                ]),
                actor: $request->user()
            );
        } catch (Throwable $throwable) {
            report($throwable);

            if ($throwable instanceof ValidationException) {
                return response()->json([
                    'message' => 'Unable to purchase KulCoins.',
                    'errors' => $throwable->errors(),
                ], 422);
            }

            return response()->json([
                'message' => 'Unable to purchase KulCoins.',
                'error' => $throwable->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => 'KulCoin package purchased successfully.',
            'data' => new KulCoinTransactionResource($transaction),
        ], 201);
    }

    public function sendGift(Request $request)
    {
        $validated = $request->validate([
            'gift_id' => ['required', 'integer', 'exists:kulcoin_gifts,id'],
            'creator_id' => ['required', 'integer', 'exists:users,id'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'message' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
            'device_info' => ['nullable', 'array'],
        ]);

        try {
            $gift = KulCoinGift::query()->findOrFail($validated['gift_id']);
            $creator = User::query()->findOrFail($validated['creator_id']);

            $transaction = $this->kulCoinService->sendGift(
                sender: $request->user(),
                creator: $creator,
                gift: $gift,
                quantity: (int) ($validated['quantity'] ?? 1),
                data: array_merge($validated, [
                    'ip_address' => $request->ip(),
                    'device_info' => $validated['device_info'] ?? null,
                ]),
                actor: $request->user()
            );
        } catch (Throwable $throwable) {
            report($throwable);

            if ($throwable instanceof ValidationException) {
                return response()->json([
                    'message' => 'Unable to send gift.',
                    'errors' => $throwable->errors(),
                ], 422);
            }

            return response()->json([
                'message' => 'Unable to send gift.',
                'error' => $throwable->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => 'Gift sent successfully.',
            'data' => new KulCoinTransactionResource($transaction),
        ], 201);
    }

    public function vote(Request $request)
    {
        $validated = $request->validate([
            'contest_type' => ['required', 'string', 'max:120'],
            'contest_id' => ['nullable', 'string', 'max:120'],
            'target_id' => ['nullable', 'integer'],
            'vote_count' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
            'device_info' => ['nullable', 'array'],
        ]);

        try {
            $transaction = $this->kulCoinService->castVote(
                user: $request->user(),
                data: array_merge($validated, [
                    'ip_address' => $request->ip(),
                    'device_info' => $validated['device_info'] ?? null,
                ]),
                actor: $request->user()
            );
        } catch (Throwable $throwable) {
            report($throwable);

            if ($throwable instanceof ValidationException) {
                return response()->json([
                    'message' => 'Unable to cast vote.',
                    'errors' => $throwable->errors(),
                ], 422);
            }

            return response()->json([
                'message' => 'Unable to cast vote.',
                'error' => $throwable->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => 'Vote submitted successfully.',
            'data' => new KulCoinTransactionResource($transaction),
        ], 201);
    }

    public function bonus(Request $request)
    {
        abort_unless($request->user()->roles()->where('name', 'admin')->exists(), 403, 'You are not allowed to issue bonus coins.');

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'coins' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $user = User::query()->findOrFail($validated['user_id']);

            $transaction = $this->kulCoinService->issueBonusCoins(
                user: $user,
                coins: (int) $validated['coins'],
                reason: $validated['reason'] ?? null,
                actor: $request->user(),
                metadata: [
                    'issued_by' => $request->user()->id,
                    'reason' => $validated['reason'] ?? null,
                ]
            );
        } catch (Throwable $throwable) {
            report($throwable);

            if ($throwable instanceof ValidationException) {
                return response()->json([
                    'message' => 'Unable to issue bonus coins.',
                    'errors' => $throwable->errors(),
                ], 422);
            }

            return response()->json([
                'message' => 'Unable to issue bonus coins.',
                'error' => $throwable->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => 'Bonus coins issued successfully.',
            'data' => new KulCoinTransactionResource($transaction),
        ], 201);
    }
}
