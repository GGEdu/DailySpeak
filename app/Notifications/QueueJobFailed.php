<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Horizon mails long waits but not failed jobs, so this one is sent by HorizonServiceProvider.
 * It carries the job and the exception class only: the message may echo user content.
 */
class QueueJobFailed extends Notification
{
    public function __construct(
        public string $job,
        public string $queue,
        public string $exception,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject(config('app.name').': a queue job failed')
            ->line("The job {$this->job} failed on the {$this->queue} queue.")
            ->line("Exception: {$this->exception}.")
            ->line('The details are in the failed jobs list in Horizon.');
    }
}
