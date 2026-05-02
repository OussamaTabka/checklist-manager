<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TesterAssignedToProjectNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Project $project,
        private readonly ?User $assignedBy = null
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'project_assignment',
            'title' => 'New project assignment',
            'message' => sprintf(
                'You have been assigned to project "%s"%s.',
                $this->project->name,
                $this->assignedBy?->name ? ' by '.$this->assignedBy->name : ''
            ),
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'app_url' => $this->project->app_url,
            'assigned_by' => $this->assignedBy?->only(['id', 'name', 'email']),
            'assigned_at' => now()->toIso8601String(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('New project assignment')
            ->line(sprintf('You have been assigned to project "%s".', $this->project->name))
            ->line('Open the workspace to review the project and start your checklist work.');
    }
}
