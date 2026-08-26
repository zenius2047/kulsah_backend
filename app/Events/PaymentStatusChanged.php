<?php

namespace App\Events;

use App\Models\Payment;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentStatusChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Payment $payment) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.'.$this->payment->user_id.'.payments')];
    }

    public function broadcastAs(): string
    {
        return 'payment.status.changed';
    }

    public function broadcastWith(): array
    {
        return ['type' => $this->broadcastAs(), 'payment_id' => $this->payment->id, 'reference' => $this->payment->reference, 'purpose' => $this->payment->purpose, 'status' => $this->payment->status, 'fulfilled_at' => $this->payment->fulfilled_at?->toIso8601String()];
    }
}
