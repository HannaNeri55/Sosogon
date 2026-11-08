<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A name offered as a checkbox on the owner forms; listings keep their own amenity text. */
class AmenitySuggestion extends Model
{
    public const SCOPES = ['property', 'unit', 'both'];

    protected $fillable = ['name', 'scope', 'sort_order'];
}
