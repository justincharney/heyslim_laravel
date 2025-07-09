<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use HasFactory;

    protected $fillable = ["name", "description", "slack_webhook_url"];
    public $timestamps = true;

    public function users()
    {
        return $this->belongsToMany(
            User::class,
            config("permission.table_names.model_has_roles"),
            "team_id", // foreign key on the pivot table for the team
            "model_id" // related key on the pivot table for the user
        );
    }

    /**
     * Route notifications for the Slack channel.
     *
     * @param  \Illuminate\Notifications\Notification  $notification
     * @return string|null
     */
    public function routeNotificationForSlack($notification): ?string
    {
        return $this->slack_webhook_url;
    }
}
