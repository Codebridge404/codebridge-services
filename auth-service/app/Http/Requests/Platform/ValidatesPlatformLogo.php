<?php

namespace App\Http\Requests\Platform;

use App\Services\ImageService;
use Illuminate\Validation\Validator;

trait ValidatesPlatformLogo
{
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->hasFile('logo')) {
                try {
                    app(ImageService::class)->assertSafeRasterSource($this->file('logo'));
                } catch (\InvalidArgumentException $e) {
                    $v->errors()->add('logo', $e->getMessage());
                }
            }

            foreach (['logo_url', 'logo_data'] as $field) {
                $val = $this->input($field);
                if (is_string($val) && trim($val) !== '' && ! ImageService::isSafeImageUrlOrDataUri($val)) {
                    $v->errors()->add($field, 'Unsupported or unsafe logo URL/data URI format (SVGs are not permitted).');
                }
            }
        });
    }
}
