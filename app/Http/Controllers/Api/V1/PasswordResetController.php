<?php
namespace App\Http\Controllers\Api\V1;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

// Recovery proves ownership by email OTP rather than an existing session.
// Its challenge namespace is separate from authenticated password migration.
class PasswordResetController extends PasswordMigrationController
{
    private function recoveryAccount(Request $request): ?User
    {
        $data=$request->validate(['email'=>['required','email','max:190']]);
        $user=User::whereRaw('lower(email)=?', [strtolower(trim($data['email']))])->first();
        // This resolver is used only inside the inherited OTP workflow. No route
        // authentication or session is granted until the proof is completed.
        $request->setUserResolver(fn () => $user);
        return $user;
    }
    public function requestOtp(Request $request)
    {
        $user=$this->recoveryAccount($request);
        if (! $user || $user->firebase_disabled) return $this->success(['sent'=>true,'expires_in'=>600]);
        return parent::requestOtp($request);
    }
    public function verifyOtp(Request $request)
    {
        if (! $this->recoveryAccount($request)) throw ValidationException::withMessages(['code'=>['The verification code is invalid or expired.']]);
        return parent::verifyOtp($request);
    }
    public function complete(Request $request)
    {
        if (! $this->recoveryAccount($request)) throw ValidationException::withMessages(['verification_token'=>['The verification token is invalid or expired.']]);
        return parent::complete($request);
    }
    protected function otpKey(string $email): string { return 'celeone:password-reset:v2:otp:'.hash('sha256',$email); }
    protected function tokenKey(string $email): string { return 'celeone:password-reset:v2:token:'.hash('sha256',$email); }
}
