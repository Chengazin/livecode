<?php

namespace App\Http\Requests\ProjectSpeech;

use App\Support\ProjectSpeech\ProjectSpeechValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class TranscribeProjectSpeechRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return ProjectSpeechValidation::transcribeRules();
    }

    public function audio(): ?UploadedFile
    {
        $audio = $this->validated('audio');

        return $audio instanceof UploadedFile ? $audio : null;
    }

    public function language(): ?string
    {
        $value = $this->validated('language');

        return is_string($value) ? $value : null;
    }
}
