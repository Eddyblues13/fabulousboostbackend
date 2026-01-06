<?php
namespace App\Jobs;

use App\Models\GeneralNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreateGeneralNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function handle(): void
    {
        GeneralNotification::create([
            'user_id' => $this->data['user_id'],
            'type' => $this->data['type'],
            'title' => $this->data['title'],
            'message' => $this->data['message'],
        ]);
    }
}
