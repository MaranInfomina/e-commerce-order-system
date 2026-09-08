<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ProductImageRequest',
    required: ['image'],
    properties: [
        new OA\Property(property: 'image', type: 'string', format: 'binary', description: 'jpeg, jpg, png, or webp, max 2MB (NFR-15).'),
    ],
)]
class ProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorize before validating, the same ordering ProductStoreRequest
        // and ProductUpdateRequest have used since Task 6. There is no
        // enumeration oracle to close here — the rules below are static, with
        // no unique/exists lookup whose per-field 422 could leak a row — but
        // `return true` would still let an unauthorised caller push a 2 MB
        // body through the multipart parser and a finfo content inspection on
        // every request before the controller's own check refuses it.
        // Delegates to the policy so there is one source of truth; the
        // controller keeps $this->authorize('update', $product) for the
        // DELETE route, which takes no form request.
        return $this->user()?->can('update', $this->route('product')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `image` inspects the file's actual content, so a text file
            // renamed .jpg is rejected. `mimes` then restricts to the three
            // formats worth serving. max is in kilobytes: 2 MB (NFR-15).
            'image' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
        ];
    }
}
