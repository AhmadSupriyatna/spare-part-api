<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A managed suggestion list for Part.category, same relationship as Unit is
 * to Part.unit — `parts.category` stays a plain string column (not a FK),
 * this just gives the dropdown a controlled set of values instead of
 * free text.
 */
class Category extends Model
{
    protected $fillable = [
        'name',
    ];
}
