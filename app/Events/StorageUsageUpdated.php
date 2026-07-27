<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StorageUsageUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param array{
     *     used_bytes: int,
     *     max_bytes: int,
     *     used_formatted: string,
     *     max_formatted: string,
     *     is_full: bool,
     *     percentage: int|float
     * } $storage
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $storage,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("App.Models.User.{$this->userId}"),
        ];
    }

    /**
     * The event name that gets received by the frontend.
     */
    public function broadcastAs(): string
    {
        return 'storage.updated';
    }

    /**
     * The data sent to the frontend
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'storage' => $this->storage,
        ];
    }
}
