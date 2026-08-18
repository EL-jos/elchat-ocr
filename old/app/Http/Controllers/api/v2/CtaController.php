<?php

namespace App\Http\Controllers\api\v2;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitChatFormRequest;
use App\Mail\ChatFormSubmittedMail;
use App\Models\ChatbotCta;
use App\Models\ChatFormSubmission;
use App\Models\Site;
use App\Services\cta\CtaRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class CtaController extends Controller
{
    public function __construct(protected CtaRepository $repository) {}

    /**
     * Display a listing of the resource.
     */
    /*public function index(Site $site)
    {
        $ctas = $this->repository->getForSite($site->id);
        return response()->json($ctas);
    }*/
    public function index(Request $request, Site $site)
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100']
        ]);

        $perPage = $validated['per_page'] ?? 10;

        $ctas = $this->repository->paginateForSite(
            $site->id,
            $perPage
        );

        return response()->json($ctas);
    }
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Site $site)
    {

        //dd($request->all());
        $validated = $request->validate([
            'label' => 'required|string|max:255',
            'action' => 'required|string|max:255',
            'value' => 'nullable|string',
            'position' => 'nullable|integer',
            'style' => 'nullable|string|max:255',
            'max_display' => 'nullable|integer',
            'rules' => 'nullable|array',
            'rules.*.rule_type' => 'required|string',
            'rules.*.rule_value' => 'required|string',
        ]);

        $cta = $this->repository->create(array_merge($validated, [
            'site_id' => $site->id
        ]));

        return response()->json($cta, 201);
    }
    /**
     * Display the specified resource.
     */
    public function show(Site $site, ChatbotCta $cta)
    {
        //$this->authorize('view', $cta);
        return response()->json($cta->load('rules'));
    }
    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Site $site, ChatbotCta $cta)
    {
        $validated = $request->validate([
            'label' => 'sometimes|required|string|max:255',
            'action' => 'sometimes|required|string|max:255',
            'value' => 'nullable|string',
            'position' => 'nullable|integer',
            'style' => 'nullable|string|max:255',
            'max_display' => 'nullable|integer',
            'rules' => 'nullable|array',
            'rules.*.rule_type' => 'required|string',
            'rules.*.rule_value' => 'required|string',
        ]);

        $cta = $this->repository->update($cta, $validated);

        return response()->json($cta);
    }
    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Site $site, ChatbotCta $cta)
    {
        $this->repository->delete($cta->id);
        return response()->json(['message' => 'CTA deleted successfully']);
    }
    public function destroyAll(Site $site){
        $count = $this->repository->deleteAllForSite($site->id);
        if ($count){
            return response()->json([
                'message' => 'CTA deleted successfully',
                'count' => $count
            ]);
        }
    }
    public function destroyMultiple(Request $request, Site $site)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'uuid']
        ]);

        $count = $this->repository->deleteManyForSite(
            $site->id,
            $validated['ids']
        );

        return response()->json([
            'message' => 'CTAs deleted successfully',
            'count' => $count
        ]);
    }

    public function submitForm(SubmitChatFormRequest $request, Site $site){
        try {
            
            $validated = $request->validated();
            Log::info("DANS SUBMITFORM", [
                'site_id' => $site->id,
                'message_id' => $validated['message_id'],
                'form_id' => $validated['form_id'],
                'values' => $validated['values']
            ]);

            // STORE SUBMISSION
            $submission = ChatFormSubmission::create([
                'site_id' => $site->id,
                'message_id' => $validated['message_id'],
                'form_id' => $validated['form_id'],
                'values' => $validated['values']
            ]);
            // EMAIL DESTINATION
            $email = $site->account->email;

            if ($email) {
                Mail::to($email)->send( new ChatFormSubmittedMail( $site, $validated['form_id'], $validated['values'] ) );
            }

            return response()->json([ 'success' => true, 'message' => 'Formulaire envoyé avec succès.', 'submission_id' => $submission->id ]);
        } catch (Throwable $e) {
            report($e);
            return response()->json([ 'success' => false, 'message' => 'Erreur lors de l\'envoi du formulaire.' ], 500);
        }
    }
}
