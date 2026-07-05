<?php

namespace App\Services\CvParser;

use Illuminate\Http\Client\Response;

readonly class OpenRouterError
{
    public function __construct(
        public ?int $code,
        public string $message,
        public ?int $status,
        public ?string $provider = null,
        public ?string $errorType = null,
    ) {}

    public static function fromResponse(Response $response): self
    {
        $json = $response->json();

        $message = trim((string) (
            data_get($json, 'error.message')
            ?? data_get($json, 'message')
            ?? ''
        ));

        if ($message === '') {
            $message = 'Unknown OpenRouter error';
        }

        $code = data_get($json, 'error.code');
        $code = is_numeric($code) ? (int) $code : null;

        $provider = data_get($json, 'error.metadata.provider')
            ?? data_get($json, 'error.metadata.provider_name')
            ?? data_get($json, 'provider');
        $provider = is_string($provider) && $provider !== '' ? $provider : null;

        $errorType = data_get($json, 'error.metadata.error_type');
        $errorType = is_string($errorType) && $errorType !== '' ? $errorType : null;

        return new self(
            code: $code,
            message: $message,
            status: $response->status() > 0 ? $response->status() : null,
            provider: $provider,
            errorType: $errorType,
        );
    }

    public function userMessage(): string
    {
        $errorTypeMessage = $this->messageForErrorType($this->errorType);

        if ($errorTypeMessage !== null) {
            return $errorTypeMessage;
        }

        $patternMessage = $this->messageForPattern($this->message);

        if ($patternMessage !== null) {
            return $patternMessage;
        }

        if (! $this->isGenericMessage($this->message)) {
            return $this->message;
        }

        return $this->messageForStatus($this->status ?? $this->code)
            ?? 'CV parsing failed. Try again in a moment.';
    }

    private function messageForErrorType(?string $errorType): ?string
    {
        return match ($errorType) {
            'rate_limit_exceeded' => $this->isProviderError()
                ? "{$this->providerSubject()} is rate limiting requests. Wait and try again, or switch to a different model."
                : 'OpenRouter rate limit reached for this API key. Wait and try again.',
            'provider_overloaded' => "{$this->providerSubject()} is temporarily overloaded. Try again shortly.",
            'provider_unavailable' => "{$this->providerSubject()} is unavailable right now. Try again or use a different model.",
            'authentication_error' => 'Invalid OpenRouter API key or authentication failed.',
            'insufficient_credits' => 'Insufficient API credits on your OpenRouter account.',
            'context_length_exceeded', 'token_limit_exceeded', 'max_tokens_exceeded' => 'The CV is too large for this model. Try a model with a larger context window.',
            'invalid_request', 'invalid_request_error' => 'The parse request was rejected. Check your PDF and model configuration.',
            'moderation_flagged', 'content_filter' => 'The request was blocked by the provider content filter.',
            default => null,
        };
    }

    private function messageForPattern(string $message): ?string
    {
        $normalized = strtolower($message);

        return match (true) {
            str_contains($normalized, 'rate limit'),
            str_contains($normalized, 'too many requests') => $this->isProviderError()
                ? "{$this->providerSubject()} is rate limiting requests. Wait and try again, or switch to a different model."
                : 'OpenRouter rate limit reached for this API key. Wait and try again.',
            str_contains($normalized, 'insufficient credit'),
            str_contains($normalized, 'payment required'),
            str_contains($normalized, 'negative credit') => 'Insufficient API credits on your OpenRouter account.',
            str_contains($normalized, 'invalid api key'),
            str_contains($normalized, 'unauthorized'),
            str_contains($normalized, 'authentication'),
            str_contains($normalized, 'invalid credentials') => 'Invalid OpenRouter API key or authentication failed.',
            str_contains($normalized, 'unable to parse pdf'),
            str_contains($normalized, 'failed to parse pdf') => 'The PDF could not be parsed. Try a different file or PDF engine.',
            str_contains($normalized, 'context length'),
            str_contains($normalized, 'token limit'),
            str_contains($normalized, 'maximum context') => 'The CV is too large for this model. Try a model with a larger context window.',
            str_contains($normalized, 'timed out'),
            str_contains($normalized, 'timeout') => $this->isProviderError()
                ? 'The provider timed out while processing your CV. Try again or use a faster model.'
                : 'The parse request timed out. Try again or use a faster model.',
            str_contains($normalized, 'moderation'),
            str_contains($normalized, 'content filter'),
            str_contains($normalized, 'flagged') => 'The request was blocked by the provider content filter.',
            str_contains($normalized, 'model not found'),
            str_contains($normalized, 'no endpoints found') => 'The configured model is not available on OpenRouter.',
            str_contains($normalized, 'overloaded') => "{$this->providerSubject()} is temporarily overloaded. Try again shortly.",
            str_contains($normalized, 'service unavailable') => $this->isProviderError()
                ? "{$this->providerSubject()} is temporarily unavailable. Try again shortly."
                : 'OpenRouter could not route to an available provider. Try again or switch models.',
            str_contains($normalized, 'provider returned error'),
            str_contains($normalized, 'provider returned an error') => $this->messageForStatus($this->status ?? $this->code)
                ?? 'The provider returned an error. Try again or use a different model.',
            default => null,
        };
    }

    private function messageForStatus(?int $status): ?string
    {
        return match ($status) {
            400 => 'Invalid parse request. Check your PDF and model configuration.',
            401 => 'Invalid OpenRouter API key or authentication failed.',
            402 => 'Insufficient API credits on your OpenRouter account.',
            403 => $this->isProviderError()
                ? 'The provider blocked this request.'
                : 'OpenRouter blocked this request.',
            408 => $this->isProviderError()
                ? 'The provider timed out while processing your CV. Try again or use a faster model.'
                : 'The parse request timed out. Try again or use a faster model.',
            429 => $this->isProviderError()
                ? "{$this->providerSubject()} is rate limiting requests. Wait and try again, or switch to a different model."
                : 'OpenRouter rate limit reached for this API key. Wait and try again.',
            502 => "{$this->providerSubject()} is unavailable right now. Try again or use a different model.",
            503 => $this->isProviderError()
                ? "{$this->providerSubject()} is temporarily unavailable. Try again shortly."
                : 'OpenRouter could not route to an available provider. Try again or switch models.',
            504, 524 => $this->isProviderError()
                ? 'The provider timed out while processing your CV. Try again or use a faster model.'
                : 'The parse request timed out while waiting for a response.',
            default => null,
        };
    }

    private function isProviderError(): bool
    {
        if ($this->provider !== null) {
            return true;
        }

        if (in_array($this->errorType, ['provider_overloaded', 'provider_unavailable'], true)) {
            return true;
        }

        $normalized = strtolower($this->message);

        return str_contains($normalized, 'provider returned error')
            || str_contains($normalized, 'provider returned an error');
    }

    private function providerSubject(): string
    {
        return 'The provider';
    }

    private function isGenericMessage(string $message): bool
    {
        $normalized = strtolower(trim($message));

        return in_array($normalized, [
            'provider returned error',
            'provider returned an error',
            'unknown openrouter error',
            'internal server error',
            'error',
        ], true);
    }
}
