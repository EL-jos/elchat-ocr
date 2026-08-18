<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\AIRole;
use Illuminate\Http\Request;

class AIRoleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(AIRole::all());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(AIRole $aIRole)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, AIRole $aIRole)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AIRole $aIRole)
    {
        //
    }
}
