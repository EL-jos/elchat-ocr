<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\TypeSite;
use Illuminate\Http\Request;

class TypeSiteController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(TypeSite::all());
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
    public function show(TypeSite $typeSite)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, TypeSite $typeSite)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(TypeSite $typeSite)
    {
        //
    }
}
