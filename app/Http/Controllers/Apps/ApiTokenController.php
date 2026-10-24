<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ApiTokenController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService
    ) {}

    /**
     * Daftar token API milik user yang sedang login.
     *
     * Token tidak pernah dikirim utuh ke halaman ini: yang tampil hanya
     * metadata (nama, abilities, terakhir dipakai). Nilai token hanya muncul
     * sekali, sesaat setelah dibuat, lewat flash Inertia.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $tokens = $user->tokens()
            ->latest()
            ->get(['id', 'name', 'abilities', 'last_used_at', 'created_at']);

        return Inertia::render('Dashboard/Settings/ApiTokens', [
            'tokens' => $tokens,
            'availableAbilities' => $user->getAllPermissions()
                ->pluck('name')
                ->sort()
                ->values(),
        ]);
    }

    /**
     * Buat token baru untuk user yang sedang login.
     *
     * Abilities yang diminta dipotong ke permission yang dimiliki pembuatnya
     * (tidak boleh mencetak token yang lebih kuat dari dirinya sendiri).
     * `user:read` selalu ikut, sama seperti token hasil login API.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'abilities' => 'nullable|array',
            'abilities.*' => 'string',
        ]);

        $user = $request->user();

        $owned = $user->getAllPermissions()->pluck('name')->all();
        $abilities = collect($validated['abilities'] ?? [])
            ->intersect($owned)
            ->values()
            ->all();
        $abilities[] = 'user:read';
        $abilities = array_values(array_unique($abilities));

        $token = $user->createToken($validated['name'], $abilities);

        $this->auditLogService->log(
            event: 'api_token.created',
            module: 'api_tokens',
            auditable: $user,
            description: 'Token API dibuat.',
            meta: [
                'token_id' => $token->accessToken->id,
                'token_name' => $validated['name'],
                'abilities' => $abilities,
            ]
        );

        // Flash Inertia: token tampil sekali di halaman berikutnya, lalu hilang.
        Inertia::flash('api_token', $token->plainTextToken);
        Inertia::flash('api_token_name', $validated['name']);

        return redirect()
            ->route('settings.api-tokens.index')
            ->with('success', 'Token API berhasil dibuat. Salin sekarang — token hanya tampil sekali.');
    }

    /**
     * Cabut token milik sendiri. Token orang lain tidak bisa dihapus dari sini
     * (pencarian dibatasi ke token milik user yang sedang login → 404).
     */
    public function destroy(Request $request, $token)
    {
        $user = $request->user();

        $accessToken = $user->tokens()->findOrFail($token);
        $name = $accessToken->name;
        $accessToken->delete();

        $this->auditLogService->log(
            event: 'api_token.revoked',
            module: 'api_tokens',
            auditable: $user,
            description: 'Token API dicabut.',
            meta: [
                'token_id' => $token,
                'token_name' => $name,
            ]
        );

        return redirect()
            ->route('settings.api-tokens.index')
            ->with('success', 'Token API berhasil dicabut.');
    }
}
