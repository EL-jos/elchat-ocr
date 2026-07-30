<?php

namespace App\Traits;

use App\Models\Account;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Trait JwtAuth
 *
 * Remplace Auth::user() et $user->account dans TOUS les controllers
 * qui fonctionnaient avec la session Laravel classique.
 *
 * Usage dans un controller :
 *   use App\Traits\JwtAuth;
 *   class MyController extends Controller {
 *       use JwtAuth;
 *       public function index(Request $request) {
 *           $user    = $this->jwtUser($request);
 *           $account = $this->jwtAccount($request);
 *       }
 *   }
 */
trait JwtAuth
{
    /**
     * Retourne l'utilisateur authentifié via JWT.
     * Équivalent de Auth::user()
     */
    protected function jwtUser(Request $request): ?User
    {
        return $request->get('jwt_user');
    }

    /**
     * Retourne l'Account de l'utilisateur authentifié.
     * Équivalent de Auth::user()->account
     * ou $user->ownedAccount
     */
    protected function jwtAccount(Request $request): ?Account
    {
        return $request->get('jwt_account');
    }

    /**
     * Retourne l'ID de l'utilisateur.
     * Équivalent de Auth::id()
     */
    protected function jwtUserId(Request $request): ?string
    {
        return $this->jwtUser($request)?->id;
    }

    /**
     * Vérifie si l'utilisateur est authentifié.
     */
    protected function jwtCheck(Request $request): bool
    {
        return !is_null($this->jwtUser($request));
    }

    /**
     * Assert que l'utilisateur et son account existent.
     * Lève une exception HTTP 401/404 sinon.
     */
    protected function requireJwtAccount(Request $request): Account
    {
        $account = $this->jwtAccount($request);

        if (!$account) {
            abort(404, 'Aucun compte associé à cet utilisateur.');
        }

        return $account;
    }
}
