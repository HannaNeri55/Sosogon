<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * The requested ?per_page, kept between 1 and $max. A missing or non-numeric value gets
     * $default; a zero or negative one would otherwise reach the database as a broken LIMIT.
     */
    protected function perPage(Request $request, int $default, int $max): int
    {
        $perPage = filter_var($request->input('per_page'), FILTER_VALIDATE_INT);

        return $perPage === false ? $default : max(1, min($perPage, $max));
    }
}
