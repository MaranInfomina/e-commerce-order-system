<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(private readonly TokenService $tokens) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            ...$request->validated(),
            'role' => User::ROLE_CUSTOMER,
        ]);

        return UserResource::make($user)->response()->setStatusCode(201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $user = User::where('email', $credentials['email'])->first();

        // One branch covers both "no such user" and "wrong password" so the
        // response cannot be used to enumerate accounts.
        //
        // The placeholder must be a syntactically valid 60-character bcrypt
        // digest at the same cost the application hashes with. Hash::check()
        // verifies the algorithm before comparing and throws a RuntimeException
        // on anything password_get_info() cannot identify as bcrypt, which
        // would turn an unknown email into a 500 and re-open the very oracle
        // this branch exists to close. It is a hash of a discarded random
        // value, so no password can ever match it.
        $hash = $user?->password
            ?? '$2y$12$kMKZMalBU0ebzcbI0Z75MOIR9Px.nouUxadqj861DOTxCD.zkEuc2';

        // Run the comparison UNCONDITIONALLY, then branch on the result.
        // Writing `$user === null || ! Hash::check(...)` looks equivalent and
        // is not: `||` short-circuits, so Hash::check never executes for an
        // unknown email. That returns in ~1ms while a known email pays a full
        // bcrypt at cost 12 — a timing oracle that enumerates accounts just as
        // effectively as differing response bodies would. Assigning first is
        // what actually makes both paths cost the same.
        $passwordMatches = Hash::check($credentials['password'], $hash);

        if ($user === null || ! $passwordMatches) {
            throw new AuthenticationException;
        }

        $issued = $this->tokens->issue($user);

        return response()->json([
            'token' => $issued['token'],
            'token_type' => 'Bearer',
            'expires_in' => $issued['expires_in'],
        ]);
    }
}
