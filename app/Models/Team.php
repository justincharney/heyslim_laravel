<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use HasFactory;

    protected $fillable = ["name", "description"];

    public function users()
    {
        return $this->belongsToMany(
            User::class,
            config("permission.table_names.model_has_roles"),
            "team_id", // foreign key on the pivot table for the team
            "model_id" // related key on the pivot table for the user
        );
    }
}
