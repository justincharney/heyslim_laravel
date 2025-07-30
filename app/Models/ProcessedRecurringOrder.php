<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProcessedRecurringOrder extends Model
{
    use HasFactory;

    protected $fillable = ["prescription_id", "chargebee_invoice_id"];
}
