<?php

namespace App\Http\Controllers\Admin;

use App\Services\ReputationService;
use Illuminate\Http\Request;

class AbuseController extends AdminController
{
    public function __invoke(Request $request, ReputationService $rep)
    {
        $days = in_array((int) $request->query('days'), [1, 7, 30], true) ? (int) $request->query('days') : 7;
        $rows = $rep->scan($days);
        if ($lvl = $request->query('level')) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['level'] === $lvl));
        }

        return view('admin.abuse', ['rows' => $rows, 'days' => $days]);
    }
}
