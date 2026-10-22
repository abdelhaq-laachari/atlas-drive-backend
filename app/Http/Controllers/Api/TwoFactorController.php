<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Fortify;

/**
 * My account → two-step verification (Google Authenticator, Microsoft Authenticator...).
 * 1. POST   /api/account/two-factor           { current_password }  → QR code + setup key
 * 2. POST   /api/account/two-factor/confirm   { code }              → on; recovery codes shown once
 *    POST   /api/account/two-factor/recovery-codes { current_password } → new recovery codes
 *    DELETE /api/account/two-factor           { current_password }  → off
 * Answers with secrets are never cached by the browser.
 */
class TwoFactorController extends Controller
{
    public function store(Request $request, EnableTwoFactorAuthentication $enable): JsonResponse
    {
        $this->requirePassword($request);
        $user = $request->user();

        abort_if($user->hasEnabledTwoFactorAuthentication(), 409, 'Two-step verification is already on.');

        // A new secret every time setup starts (an unfinished setup is replaced)
        $enable($user, force: true);

        return $this->private([
            'qr_svg' => $user->twoFactorQrCodeSvg(),
            // For typing into the app by hand when the QR code can't be scanned
            'setup_key' => Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
        ]);
    }

    public function confirm(Request $request, ConfirmTwoFactorAuthentication $confirm): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        abort_if($user->hasEnabledTwoFactorAuthentication(), 409, 'Two-step verification is already on.');
        abort_if(empty($user->two_factor_secret), 409, 'Start the setup first.');

        $confirm($user, $request->input('code'));

        return $this->private(['recovery_codes' => $user->fresh()->recoveryCodes()]);
    }

    public function recoveryCodes(Request $request, GenerateNewRecoveryCodes $generate): JsonResponse
    {
        $this->requirePassword($request);
        $user = $request->user();

        abort_unless($user->hasEnabledTwoFactorAuthentication(), 409, 'Two-step verification is off.');

        $generate($user);

        return $this->private(['recovery_codes' => $user->fresh()->recoveryCodes()]);
    }

    public function destroy(Request $request, DisableTwoFactorAuthentication $disable): Response
    {
        $this->requirePassword($request);

        $disable($request->user());

        return response()->noContent();
    }

    private function requirePassword(Request $request): void
    {
        $request->validate(
            ['current_password' => ['required', 'current_password:web']],
            ['current_password.current_password' => 'Your current password is not correct.'],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function private(array $data): JsonResponse
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }
}
