<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserFile extends Model
{
    use HasFactory;

    protected $fillable = [
        "user_id",
        "file_name",
        "supabase_path",
        "mime_type",
        "size",
        "description",
        "uploaded_at",
    ];

    protected $casts = [
        "uploaded_at" => "datetime",
        "size" => "integer",
    ];

    // Get the user who uploaded the file
    public function user(): BelongsTo
    {
        return $this->BelongsTo(User::class);
    }
}
