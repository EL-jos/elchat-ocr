<?php

namespace App\Traits;

use App\Models\Account;
use App\Models\User;
use Illuminate\Http\Request;

trait JwtAuth
{
    /**
     * Retourne l'utilisateur authentifié via JWT.
     */
    protected function jwtUser(Request $request): ?User
    {
        return auth()->user();
    }

    /**
     * Retourne l'Account possédé par l'utilisateur authentifié.
     */
    protected function jwtAccount(Request $request): ?Account
    {
        return auth()->user()?->ownedAccount;
    }

    protected function jwtUserId(Request $request): ?string
    {
        return $this->jwtUser($request)?->id;
    }

    protected function jwtCheck(Request $request): bool
    {
        return !is_null($this->jwtUser($request));
    }

    /**
     * Assert que l'utilisateur possède un account. Lève une 404 sinon.
     */
    protected function requireJwtAccount(Request $request): Account
    {
        $account = auth()->user()?->ownedAccount;

        if (!$account) {
            abort(404, 'No owned account');
        }

        return $account;
    }
}
