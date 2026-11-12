<?php

namespace App\Http\Requests;

use App\Models\Recharge;
use App\Support\CodeRecharge;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRechargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [Recharge::class, $this->route('compteur')]);
    }

    protected function prepareForValidation(): void
    {
        // « 1234 5678-9012 … » → « 12345678…» ; un SMS CIE collé en entier est aussi accepté.
        $saisie = (string) $this->input('code');
        $code = CodeRecharge::normaliser($saisie);
        if (strlen($code) !== CodeRecharge::LONGUEUR) {
            $code = CodeRecharge::extraireDuSms($saisie)?->valeur() ?? $code;
        }

        $this->merge(['code' => $code]);
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'digits:20'],
            'montant_fcfa' => ['required', 'integer', 'min:100', 'max:10000000'],
            'credit_kwh' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'date_achat' => ['required', 'date', 'before_or_equal:now'],
            'mode_paiement' => ['required', Rule::in(['orange_money', 'mtn_money', 'moov_money', 'wave', 'agence', 'autre'])],
            'reference_paiement' => ['nullable', 'string', 'max:64'],
            'observation' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return ['code.digits' => 'Le code de recharge doit contenir exactement 20 chiffres.'];
    }

    public function code(): CodeRecharge
    {
        return CodeRecharge::depuisSaisie($this->validated('code'));
    }
}
