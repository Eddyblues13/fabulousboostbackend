<?php

// ManageServiceUpdateController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceUpdate;

class ServiceUpdateController extends Controller
{
    public function index()
    {
        $updates = ServiceUpdate::latest()->get(); // all updates for history
        return response()->json([
            'status' => 'success',
            'data' => $updates
        ]);
    }
}

