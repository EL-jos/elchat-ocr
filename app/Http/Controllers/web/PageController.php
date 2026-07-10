<?php

namespace App\Http\Controllers\web;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContactRequest;
use App\Mail\ContactMail;
use App\Services\ContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PageController extends Controller
{

    public function __construct(
        private ContactService $contactService
    ) {}

    public function home(){
        return view('pages.home');
    }

    public function about(){
        return view('pages.about');
    }

    public function services(){
        return view('pages.services.index');
    }

    public function service(string $slug){
        return view('pages.services.show');
    }

    public function abonnements(){
        return view('pages.abonnements');
    }

    public function faqs(){
        return view('pages.faqs');
    }

    public function contact(){
        return view('pages.contact');
    }

    public function sendContact(ContactRequest $request): JsonResponse
    {
        try {

            $this->contactService->send([
                'name'       => $request->fname,
                'phone'      => $request->phone,
                'email'      => $request->email,
                'message'    => $request->msg,
                'ip'         => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Votre message a été envoyé avec succès. Nous vous répondrons dans les meilleurs délais.'
            ]);

        } catch (\Throwable $e) {

            Log::error('Erreur formulaire contact', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => "Une erreur est survenue lors de l'envoi du message."
            ], 500);

        }
    }

    public function politique_de_confidentialite(){
        return view('pages.politique_de_confidentialite');
    }

    public function cgu(){
        return view('pages.cgu');
    }
    
    public function ml(){
        return view('pages.ml');
    }

}
