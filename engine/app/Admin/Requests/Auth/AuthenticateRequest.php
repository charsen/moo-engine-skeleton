<?php

declare(strict_types=1);

namespace App\Admin\Requests\Auth;

use Mooeen\Scaffold\Foundation\FormRequest;

final class AuthenticateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'account'  => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }
}
